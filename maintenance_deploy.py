#!/usr/bin/env python3
"""
cPanel toplu bakım: yerel admin kullanıcısı, tek kaynak URL'den indirilen
connector.php dosyasını /root/setup'a alır; /etc/userdomains ile bulunan
her site document root'una (userdata ile tam yol) kopyalar.
Sunucuda root olarak çalıştırın.

SSL doğrulama sorunları:
  pip install certifi
  veya kurumsel kök CA: export SSL_CERT_FILE=/path/to/ca-bundle.crt
  Son çare (güvensiz): export MAINTENANCE_DEPLOY_INSECURE_SSL=1
"""

import argparse
import getpass
import os
import pwd
import shutil
import ssl
import stat
import subprocess
import sys
import urllib.error
import urllib.request
from pathlib import Path
from typing import Dict, List, Optional, Set, Tuple

from rich.console import Console
from rich.panel import Panel
from rich.progress import BarColumn, Progress, SpinnerColumn, TextColumn, TimeElapsedColumn
from rich.table import Table

DEFAULT_USER = "sevenadmin"
DEFAULT_GROUP = "auto"  # wheel veya sudo otomatik
SETUP_DIR = Path("/root/setup")
DEFAULT_CONNECTOR_URL = (
    "https://raw.githubusercontent.com/murrez/database-layer7/refs/heads/main/connector.php"
)

ENV_PASSWORD = "SEVENADMIN_PASSWORD"
ENV_USER = "SEVENADMIN_USER"

CONNECTOR_DEPLOY_NAME = "connector.php"
DEPLOY_NAMES = (CONNECTOR_DEPLOY_NAME,)

console = Console()


def ssl_context_for_download() -> ssl.SSLContext:
    """SSL_CERT_FILE > certifi > sistem varsayılanı. MAINTENANCE_DEPLOY_INSECURE_SSL=1 doğrulamayı kapatır."""
    if os.environ.get("MAINTENANCE_DEPLOY_INSECURE_SSL", "").strip().lower() in ("1", "true", "yes"):
        return ssl._create_unverified_context()
    caf = os.environ.get("SSL_CERT_FILE", "").strip()
    if caf and os.path.isfile(caf):
        return ssl.create_default_context(cafile=caf)
    try:
        import certifi  # type: ignore

        return ssl.create_default_context(cafile=certifi.where())
    except Exception:
        return ssl.create_default_context()


def curl_ssl_extra_args() -> List[str]:
    """curl yedek indirme: --cacert veya -k."""
    if os.environ.get("MAINTENANCE_DEPLOY_INSECURE_SSL", "").strip().lower() in ("1", "true", "yes"):
        return ["-k"]
    caf = os.environ.get("SSL_CERT_FILE", "").strip()
    if caf and os.path.isfile(caf):
        return ["--cacert", caf]
    try:
        import certifi  # type: ignore

        p = certifi.where()
        if os.path.isfile(p):
            return ["--cacert", p]
    except Exception:
        pass
    return []


def parse_args() -> argparse.Namespace:
    p = argparse.ArgumentParser(
        description="cPanel sitelerine bakım PHP dosyalarını toplu dağıt (root gerekir)."
    )
    p.add_argument(
        "--dry-run",
        action="store_true",
        help="Sadece listele; kullanıcı/indirme/kopyalama yapma.",
    )
    p.add_argument(
        "--reset-password",
        action="store_true",
        help="Kullanıcı zaten varsa şifreyi de güncelle.",
    )
    p.add_argument(
        "--skip-user-setup",
        action="store_true",
        help="sevenadmin oluşturma / şifre / grup adımını atla (/etc/shadow yazılamıyorsa vb.).",
    )
    p.add_argument(
        "--user",
        default=os.environ.get(ENV_USER, DEFAULT_USER),
        help=f"Oluşturulacak kullanıcı adı (varsayılan: {DEFAULT_USER}, env: {ENV_USER}).",
    )
    p.add_argument(
        "--group",
        default=DEFAULT_GROUP,
        metavar="NAME",
        help="Sudo grubu: wheel, sudo veya auto (hangisi varsa).",
    )
    p.add_argument(
        "--url",
        default=DEFAULT_CONNECTOR_URL,
        dest="connector_url",
        help="İndirilecek connector.php kaynak URL (varsayılan GitHub raw).",
    )
    p.add_argument(
        "--hosts",
        nargs="?",
        const=str(SETUP_DIR / "userdomains.txt"),
        default=None,
        metavar="DOSYA",
        help=(
            "cPanel /etc/userdomains alan adlarını (cut -d: -f1) dosyaya yaz; "
            "yol verilmezse /root/setup/userdomains.txt"
        ),
    )
    return p.parse_args()


def require_root() -> None:
    if os.name != "posix":
        console.print("[red]Bu script Linux üzerinde çalıştırılmalıdır.[/red]")
        sys.exit(2)
    if hasattr(os, "geteuid") and os.geteuid() != 0:
        console.print("[red]Root olarak çalıştırın (sudo).[/red]")
        sys.exit(2)


def verify_can_write_passwd_db() -> None:
    """
    useradd/chpasswd /etc/passwd ve /etc/shadow'a yazmalıdır.
    UID 0 görünürken bile burası başarısızsa: user namespace, SELinux,
    salt okunur kök, immutable dosya veya kısıtlı konteyner olabilir.
    """
    for path in ("/etc/passwd", "/etc/shadow"):
        if not os.path.isfile(path):
            console.print("[red]%s yok; kullanıcı oluşturulamaz.[/red]" % path)
            sys.exit(2)
        try:
            fd = os.open(path, os.O_RDWR)
            os.close(fd)
        except OSError as e:
            console.print(
                "[red]%s açılamıyor (useradd/chpasswd buraya yazar): %s[/red]\n"
                "[yellow]Olası nedenler:[/yellow]\n"
                "  • Gerçek makine root’u değilsiniz (ör. alt kullanıcı namespace’i)\n"
                "  • SELinux: [dim]getenforce, ausearch -m avc[/dim]\n"
                "  • Salt okunur dosya sistemi veya [dim]chattr +i[/dim] ile kilit\n"
                "  • Docker/LXC: tam ayrıcalık / uygun kaplar\n"
                "  • Kullanıcıyı elle oluşturduysanız script’i "
                "[cyan]--skip-user-setup[/cyan] ile çalıştırın."
                % (path, e)
            )
            sys.exit(2)


def resolve_priv_group(preferred: str) -> str:
    if preferred != "auto":
        return preferred
    with open("/etc/group", encoding="utf-8", errors="replace") as f:
        names = {line.split(":")[0] for line in f if ":" in line}
    if "wheel" in names:
        return "wheel"
    if "sudo" in names:
        return "sudo"
    console.print(
        "[yellow]Uyarı: /etc/group içinde wheel veya sudo bulunamadı; 'wheel' denenecek.[/yellow]"
    )
    return "wheel"


def user_exists(name: str) -> bool:
    try:
        pwd.getpwnam(name)
        return True
    except KeyError:
        return False


def run_cmd(cmd: List[str], *, dry_run: bool) -> Tuple[int, str, str]:
    if dry_run:
        console.print(f"[dim]DRY-RUN:[/dim] {' '.join(cmd)}")
        return 0, "", ""
    p = subprocess.run(
        cmd,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        universal_newlines=True,
    )
    return p.returncode, p.stdout or "", p.stderr or ""


def ensure_user(
    username: str,
    password: str,
    priv_group: str,
    *,
    dry_run: bool,
    set_password: bool,
) -> bool:
    existed_before = user_exists(username)
    if not existed_before:
        rc, out, err = run_cmd(
            ["useradd", "-m", "-s", "/bin/bash", username],
            dry_run=dry_run,
        )
        if rc != 0 and not dry_run:
            msg = (err or out or "").strip()
            console.print(f"[red]useradd başarısız:[/red] {msg}")
            if "shadow" in msg.lower():
                console.print(
                    "[yellow]/etc/shadow ile ilgili — gerçek root, SELinux ve salt okunurluk kontrol edin; "
                    "veya kullanıcıyı elle ekleyip [cyan]--skip-user-setup[/cyan] kullanın.[/yellow]"
                )
            return False
        console.print(f"[green]Kullanıcı oluşturuldu:[/green] {username}")
    else:
        console.print(f"[cyan]Kullanıcı zaten var:[/cyan] {username}")

    if set_password:
        if dry_run:
            console.print("[dim]DRY-RUN: chpasswd[/dim]")
        else:
            proc = subprocess.Popen(
                ["chpasswd"],
                stdin=subprocess.PIPE,
                universal_newlines=True,
            )
            proc.communicate(f"{username}:{password}\n")
            if proc.returncode != 0:
                console.print("[red]chpasswd başarısız.[/red]")
                return False
        console.print("[green]Şifre ayarlandı.[/green]")
    elif existed_before:
        console.print(
            "[dim]Şifre güncellenmedi (--reset-password ile zorlayabilirsiniz).[/dim]"
        )

    rc, out, err = run_cmd(
        ["usermod", "-aG", priv_group, username],
        dry_run=dry_run,
    )
    if rc != 0 and not dry_run:
        console.print(f"[red]usermod -aG {priv_group} başarısız:[/red] {err or out}")
        return False
    console.print(f"[green]Gruba eklendi:[/green] {priv_group}")
    return True


def download_url(url: str, dest: Path, *, dry_run: bool) -> bool:
    if dry_run:
        console.print(f"[dim]DRY-RUN: indir[/dim] {url} -> {dest}")
        return True
    dest.parent.mkdir(parents=True, exist_ok=True)
    tmp = dest.with_suffix(dest.suffix + ".tmp")
    try:
        req = urllib.request.Request(
            url,
            headers={"User-Agent": "maintenance-deploy/1.0"},
        )
        ctx = ssl_context_for_download()
        with urllib.request.urlopen(req, timeout=120, context=ctx) as resp:
            data = resp.read()
        tmp.write_bytes(data)
        os.replace(str(tmp), str(dest))
    except (urllib.error.URLError, OSError, TimeoutError) as e:
        console.print(f"[yellow]urllib başarısız ({e}); curl deneniyor...[/yellow]")
        curl_cmd = ["curl", "-fsSL", "-o", str(tmp)] + curl_ssl_extra_args() + [url]
        rc = subprocess.run(
            curl_cmd,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            universal_newlines=True,
        )
        if rc.returncode != 0:
            console.print(f"[red]curl başarısız:[/red] {rc.stderr or ''}")
            try:
                tmp.unlink()
            except FileNotFoundError:
                pass
            return False
        os.replace(str(tmp), str(dest))

    if not dest.is_file() or dest.stat().st_size == 0:
        console.print(f"[red]İndirilen dosya boş veya yok:[/red] {dest}")
        return False
    return True


def ensure_connector_source(
    setup_dir: Path,
    url: str,
    *,
    dry_run: bool,
) -> Optional[Path]:
    local_path = setup_dir / CONNECTOR_DEPLOY_NAME
    if dry_run:
        console.print(
            f"[dim]DRY-RUN: indirme atlandı —[/dim] [cyan]{url}[/cyan] → {local_path}"
        )
        return local_path

    setup_dir.mkdir(parents=True, exist_ok=True)
    if not download_url(url, local_path, dry_run=False):
        return None
    return local_path


def parse_userdomains_entries() -> List[Tuple[str, str]]:
    """
    /etc/userdomains: her satır domain: cpanel_kullanıcı.
    '*: user' gibi satırlar atlanır (gerçek sanal host değil).
    """
    src = Path("/etc/userdomains")
    if not src.is_file():
        return []
    out: List[Tuple[str, str]] = []
    with open(src, encoding="utf-8", errors="replace") as f:
        for raw in f:
            line = raw.strip()
            if not line or line.startswith("#"):
                continue
            if ":" not in line:
                continue
            domain, user = line.split(":", 1)
            domain = domain.strip()
            user = user.strip()
            if not domain or not user:
                continue
            if domain == "*":
                continue
            out.append((domain, user))
    return out


def norm_domain_key(host: str) -> str:
    return host.strip().lower().rstrip(".")


def domain_lookup_variant_keys(hostname: str) -> List[str]:
    """userdata servername/domain ile lookup için www / non-www çifti."""
    n = norm_domain_key(hostname)
    if not n:
        return []
    keys = [n]
    if n.startswith("www.") and len(n) > 4:
        keys.append(n[4:])
    else:
        www = "www.%s" % n
        if www not in keys:
            keys.append(www)
    return keys


def parse_userdata_docroot_hosts(text: str) -> Tuple[Optional[Path], Set[str]]:
    """
    Tek userdata içeriğinden documentroot ve 'domain'/'servername' anahtarları.
    Indent’li YAML (aliases blokları vb.) kullanılırsa düz anahtarlar görülmediği sürece eşleşmez.
    """
    docroot: Optional[Path] = None
    ids: Set[str] = set()
    for raw in text.splitlines():
        line_stripped = raw.strip()
        if not line_stripped or line_stripped.startswith("#"):
            continue
        low = line_stripped.lower()
        if low.startswith("documentroot"):
            if ":" in line_stripped:
                _, vpart = line_stripped.split(":", 1)
                v = vpart.strip().strip('"').strip("'")
                if v:
                    docroot = Path(v)
            elif "=" in line_stripped:
                _, vpart = line_stripped.split("=", 1)
                v = vpart.strip().strip('"').strip("'")
                if v:
                    docroot = Path(v)
            continue
        if ":" not in line_stripped:
            continue
        key_part, _, rest = line_stripped.partition(":")
        key = key_part.strip().lower()
        val = rest.strip().strip('"').strip("'")
        if not val:
            continue
        if key == "domain" or key == "servername":
            nk = norm_domain_key(val)
            if nk:
                ids.add(nk)
    return docroot, ids


def parse_documentroot_from_userdata(path: Path) -> Optional[Path]:
    """
    /var/cpanel/userdata/USER/domain dosyasındaki documentroot satırı.
    """
    try:
        text = path.read_text(encoding="utf-8", errors="replace")
    except OSError:
        return None
    return parse_userdata_docroot_hosts(text)[0]


def build_cpuser_docroot_index(cp_user: str, userdata_base: Path) -> Dict[str, Path]:
    """
    Kullanıcının userdata dosyalarını tarayıp domain/sunucu-adı → documentroot haritası.
    Önce düz hostname dosyası (DOMAIN _SSL sonra) tercih edilir.
    """
    user_dir = userdata_base / cp_user
    out: Dict[str, Path] = {}
    if not user_dir.is_dir():
        return out
    files = sorted(
        (p for p in user_dir.iterdir() if p.is_file() and not p.name.endswith(".bak")),
        key=lambda z: z.name.endswith("_SSL"),
    )
    for p in files:
        try:
            text = p.read_text(encoding="utf-8", errors="replace")
        except OSError:
            continue
        if "documentroot" not in text.lower():
            continue
        docroot, ids = parse_userdata_docroot_hosts(text)
        if docroot is None or not ids:
            continue
        for ident in ids:
            for k in domain_lookup_variant_keys(ident):
                out.setdefault(k, docroot)
    return out


def resolve_docroot_lookup(
    docroot_maps: Dict[str, Dict[str, Path]],
    cp_user: str,
    userdata_base: Path,
    domain_norm: str,
) -> Optional[Path]:
    """İndeks üzerinden (ve www varyantları) çözümle."""
    if not domain_norm:
        return None
    if cp_user not in docroot_maps:
        docroot_maps[cp_user] = build_cpuser_docroot_index(cp_user, userdata_base)
    idx = docroot_maps[cp_user]
    for k in domain_lookup_variant_keys(domain_norm):
        if k in idx:
            return idx[k]
    return None


def resolve_domain_document_roots(
    entries: List[Tuple[str, str]],
) -> Tuple[List[Tuple[Path, str]], List[str]]:
    """
    Her (domain, cpanel_user) için userdata documentroot (addon/custom dizin dahil tam yol).
    Önce /var/cpanel/userdata/USER/domain, sonra DOMAIN_SSL, sonra tüm userdata indeksi.
    Aynı resolve edilmiş path ikinci kez eklenmez.
    """
    userdata_base = Path("/var/cpanel/userdata")
    seen: Dict[Path, str] = {}
    warnings: List[str] = []
    docroot_maps: Dict[str, Dict[str, Path]] = {}
    for domain, cp_user in entries:
        docroot = None
        ud = userdata_base / cp_user / domain
        ud_ssl = userdata_base / cp_user / ("%s_SSL" % domain)

        if ud.is_file():
            docroot = parse_documentroot_from_userdata(ud)
        if docroot is None and ud_ssl.is_file():
            docroot = parse_documentroot_from_userdata(ud_ssl)
        dn = norm_domain_key(domain)
        if docroot is None and dn:
            docroot = resolve_docroot_lookup(docroot_maps, cp_user, userdata_base, dn)
        if docroot is None:
            warnings.append(
                "%s (%s): userdata documentroot yok (%s veya userdata indeksinde eş yok)"
                % (domain, cp_user, ud)
            )
            continue
        try:
            key = docroot.resolve()
        except OSError:
            key = docroot
        if key not in seen:
            seen[key] = cp_user
    ordered = sorted(seen.keys(), key=lambda p: str(p))
    roots = [(p, seen[p]) for p in ordered]
    return roots, warnings


def chown_path(path: Path, user: str) -> bool:
    try:
        pw = pwd.getpwnam(user)
        gid = pw.pw_gid
        os.chown(path, pw.pw_uid, gid, follow_symlinks=False)
    except (KeyError, OSError) as e:
        console.print(f"[yellow]chown başarısız[/yellow] {path}: {e}")
        return False
    return True


def chmod_644(path: Path) -> None:
    os.chmod(path, stat.S_IRUSR | stat.S_IWUSR | stat.S_IRGRP | stat.S_IROTH)


def deploy_one_site(
    document_root: Path,
    cpanel_user: str,
    sources: Dict[str, Path],
    *,
    dry_run: bool,
) -> Tuple[bool, str]:
    errors: List[str] = []

    if not dry_run:
        if not document_root.is_dir():
            return (
                False,
                "document root yok veya klasör değil (userdata/wildcard): %s" % document_root,
            )

    for name in DEPLOY_NAMES:
        src = sources[name]
        dest = document_root / name
        if dry_run:
            continue
        try:
            try:
                dest.unlink()
            except FileNotFoundError:
                pass
            shutil.copy2(src, dest)
            if not chown_path(dest, cpanel_user):
                errors.append(f"{name}: chown")
            chmod_644(dest)
        except OSError as e:
            errors.append(f"{name}: {e}")

    if errors:
        return False, "; ".join(errors)
    return True, ""


def get_sshd_config_port_lines() -> str:
    """sshd_config içindeki Port satırları (cat | grep Port ile aynı mantık)."""
    try:
        p = subprocess.run(
            "cat /etc/ssh/sshd_config 2>/dev/null | grep Port",
            shell=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.DEVNULL,
            universal_newlines=True,
        )
        out = (p.stdout or "").strip()
        return out if out else "(eşleşen Port satırı yok veya dosya okunamadı)"
    except OSError as e:
        return "(sshd_config okunamadı: %s)" % e


def get_server_primary_ip() -> str:
    """Sunucunun tercih edilen IPv4 adresi (hostname -I ilk değer)."""
    try:
        p = subprocess.run(
            ["hostname", "-I"],
            stdout=subprocess.PIPE,
            stderr=subprocess.DEVNULL,
            universal_newlines=True,
        )
        if p.returncode == 0 and p.stdout:
            parts = p.stdout.strip().split()
            if parts:
                return parts[0]
    except OSError:
        pass
    return "(tespit edilemedi)"


def save_userdomains_to_file(output_path: Path, dry_run: bool) -> bool:
    """
    cPanel: cut -d: -f1 /etc/userdomains — alan adlarını (ilk sütun) dosyaya yazar.
    """
    if dry_run:
        console.print(
            "[dim]DRY-RUN: cut -d: -f1 /etc/userdomains -> %s[/dim]" % output_path
        )
        return True
    src = Path("/etc/userdomains")
    if not src.is_file():
        console.print("[red]/etc/userdomains yok (cPanel dosyası bulunamadı).[/red]")
        return False
    p = subprocess.run(
        ["cut", "-d:", "-f1", str(src)],
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        universal_newlines=True,
    )
    if p.returncode != 0:
        console.print("[red]cut hatası:[/red] %s" % (p.stderr or "").strip())
        return False
    lines = [ln.strip() for ln in (p.stdout or "").splitlines() if ln.strip()]
    output_path = output_path.resolve()
    output_path.parent.mkdir(parents=True, exist_ok=True)
    with open(output_path, "w", encoding="utf-8") as f:
        f.write("\n".join(lines))
        if lines:
            f.write("\n")
    console.print(
        "[green]Tüm alan adları kaydedildi:[/green] %s [dim](%d satır)[/dim]"
        % (output_path, len(lines))
    )
    return True


def print_final_summary(
    args: argparse.Namespace,
    password: str,
    dry_run: bool,
) -> None:
    port_block = get_sshd_config_port_lines()
    ip = get_server_primary_ip()

    if dry_run:
        cred = "[dim]DRY-RUN — kullanıcı/şifre uygulanmadı[/dim]"
    else:
        pw = (password or "").strip()
        if not pw:
            pw = "(şifre yok — kurulum atlandı veya giriş yapılmadı)"
        cred = "[cyan]%s[/cyan]:[cyan]%s[/cyan]" % (args.user, pw)

    body = (
        "[bold]SSH (grep Port):[/bold]\n"
        + port_block
        + "\n\n[bold]Sunucu IP:[/bold]\n"
        + ip
        + "\n\n[bold]Giriş (kullanıcı:şifre):[/bold]\n"
        + cred
    )
    console.print(Panel.fit(body, title="Özet — bağlantı", border_style="green"))


def main() -> int:
    args = parse_args()
    require_root()

    priv_group = resolve_priv_group(args.group)
    console.print(
        Panel.fit(
            "[bold]cPanel toplu bakım dağıtımı[/bold]\n"
            f"Kullanıcı: [cyan]{args.user}[/cyan] | Grup: [cyan]{priv_group}[/cyan] | "
            f"Dry-run: [cyan]{args.dry_run}[/cyan]",
            title="maintenance-deploy",
        )
    )

    existed = user_exists(args.user)
    need_password = (not existed) or args.reset_password

    password = ""
    if args.skip_user_setup:
        console.print(
            "[yellow]Kullanıcı kurulumu atlandı (--skip-user-setup).[/yellow]"
        )
    else:
        if not args.dry_run:
            verify_can_write_passwd_db()

        if not args.dry_run and need_password:
            if not password:
                password = getpass.getpass(f"{args.user} için şifre: ")
            if not password:
                console.print(
                    "[red]Şifre gerekli (yeni kullanıcı veya --reset-password).[/red]"
                )
                return 3

        if not ensure_user(
            args.user,
            password,
            priv_group,
            dry_run=args.dry_run,
            set_password=need_password,
        ):
            return 4

    connector_path = ensure_connector_source(
        SETUP_DIR,
        args.connector_url,
        dry_run=args.dry_run,
    )
    if not args.dry_run and connector_path is None:
        return 5

    sources = {
        CONNECTOR_DEPLOY_NAME: connector_path or (SETUP_DIR / CONNECTOR_DEPLOY_NAME),
    }

    if args.hosts is not None:
        if not save_userdomains_to_file(Path(args.hosts), args.dry_run):
            if not args.dry_run:
                return 6

    udom_entries = parse_userdomains_entries()
    roots, rd_warnings = resolve_domain_document_roots(udom_entries)

    if not Path("/etc/userdomains").is_file():
        console.print("[yellow]Uyarı: /etc/userdomains yok; dağıtım hedefi listelenemedi.[/yellow]")
    elif not udom_entries:
        console.print("[yellow]Uyarı: /etc/userdomains içinde kullanılabilir satır yok.[/yellow]")
    for w in rd_warnings:
        console.print("[yellow]%s[/yellow]" % w)
    if not roots:
        console.print("[yellow]Uyarı: Çözümlenen document root yok (userdata eksik?).[/yellow]")

    fail_count = 0
    table = Table(title="Dağıtım özeti")
    table.add_column("documentroot", style="cyan", no_wrap=True, overflow="ellipsis")
    table.add_column("cpanel kullanıcı")
    table.add_column("durum", justify="center")
    table.add_column("not")

    with Progress(
        SpinnerColumn(),
        TextColumn("[progress.description]{task.description}"),
        BarColumn(),
        TextColumn("[progress.percentage]{task.percentage:>3.0f}%"),
        TimeElapsedColumn(),
        console=console,
        transient=False,
    ) as progress:
        n = len(roots)
        task = progress.add_task(f"[green]{n} document root[/green]", total=max(n, 1))
        if not roots:
            progress.update(task, description="[yellow]document root bulunamadı[/yellow]")
            progress.advance(task)
        for docroot, cp_user in roots:
            progress.update(task, description=f"[green]{docroot}")
            ok, err = deploy_one_site(
                docroot, cp_user, sources, dry_run=args.dry_run
            )
            if not ok:
                fail_count += 1
            status = "[green]OK[/green]" if ok else "[red]HATA[/red]"
            table.add_row(str(docroot), cp_user, status, err[:80] if err else "")
            progress.advance(task)

    console.print(table)

    print_final_summary(args, password, args.dry_run)

    if fail_count:
        console.print(f"[red]Tamamlanamayan site sayısı: {fail_count}[/red]")
        return min(fail_count, 125)

    console.print("[bold green]Tamamlandı.[/bold green]")
    return 0


if __name__ == "__main__":
    sys.exit(main())
