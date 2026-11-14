#!/usr/bin/env bash
# Allow ports 80 and 443 to Docker containers only from Cloudflare (backlog E-07).
#
# Why this exists: Docker publishes a container port with its own iptables
# rules, which run BEFORE ufw's. A ufw rule that allows 443 only from
# Cloudflare therefore does not stop anyone else from reaching a published
# port. Docker provides the DOCKER-USER chain for rules that must run first;
# this script puts one chain of Cloudflare-only rules there.
#
# Usage (as root):
#   cloudflare-only-firewall.sh apply    fetch the ranges and install the rules (idempotent)
#   cloudflare-only-firewall.sh status   show the rules
#   cloudflare-only-firewall.sh remove   take the rules out again
#
# Safe to re-run: it rebuilds only its own chain, HARA-CF, in one atomic
# step, and adds the jump from DOCKER-USER only when it is missing. It does not
# touch SSH, ufw or any other rule. If the ranges cannot be fetched or look
# wrong it changes nothing (an existing set of rules stays in force).
#
# Rules are lost at reboot. Persist them with the systemd unit in this
# directory (systemd/hara-cloudflare-firewall.service); see
# docs/operations/staging-setup.md.
#
# Environment:
#   EXT_IF   public network interface (default: the one the default route uses)
#   PORTS    space-separated original destination ports to restrict (default "80 443")
#
# Status: written and syntax-checked on the authoring workstation (bash -n).
# NOT yet run on the host.
set -euo pipefail

CHAIN="HARA-CF"
PORTS="${PORTS:-80 443}"
URL4="https://www.cloudflare.com/ips-v4"
URL6="https://www.cloudflare.com/ips-v6"

[ "$(id -u)" -eq 0 ] || { echo "Run as root (sudo)." >&2; exit 1; }
cmd="${1:-apply}"

detect_if() {
  ip -o route get 1.1.1.1 2>/dev/null | sed -n 's/.* dev \([^ ]*\).*/\1/p' | head -n 1
}
EXT_IF="${EXT_IF:-$(detect_if)}"

have_chain() { "$1" -w -S "$2" >/dev/null 2>&1; }

fetch_list() {
  local url="$1" pattern="$2" body
  body="$(curl --fail --silent --show-error --max-time 20 --proto '=https' --tlsv1.2 "$url")" || return 1
  [ -n "$body" ] || return 1
  # Only plain CIDR lines; anything else means the answer is not what we expect.
  if printf '%s\n' "$body" | grep -Ev "$pattern" | grep -q .; then return 1; fi
  # Bound each prefix (and IPv4 octets) so a broken or tampered answer can never
  # widen the allow-list to most of the internet.
  if ! printf '%s\n' "$body" | awk -F'[/.]' -v min="$3" -v max="$4" '
      { p = $NF; if (p < min || p > max) bad = 1
        if (NF == 5) for (i = 1; i <= 4; i++) if ($i > 255) bad = 1
        if ($0 ~ /^(0\.0\.0\.0|::)\//) bad = 1 }
      END { exit bad }'; then
    return 1
  fi
  printf '%s\n' "$body"
}

# $1 = iptables or ip6tables, $2 = file with one CIDR per line
install_family() {
  local ipt="$1" ranges="$2" restore payload port cidr
  restore="${ipt}-restore"
  have_chain "$ipt" DOCKER-USER || {
    echo "$ipt: no DOCKER-USER chain (is Docker running, and does it manage $ipt?); skipping." >&2
    return 0
  }
  # One atomic load of this chain only (--noflush keeps every other chain).
  payload="*filter
:$CHAIN - [0:0]
-F $CHAIN"
  while read -r cidr; do
    [ -n "$cidr" ] && payload+=$'\n'"-A $CHAIN -s $cidr -j RETURN"
  done < "$ranges"
  payload+=$'\n'"-A $CHAIN -j DROP"$'\n'"COMMIT"
  printf '%s\n' "$payload" | "$restore" --noflush -w

  # Traffic reaching a container is already NATed in DOCKER-USER, so match the
  # port the visitor asked for (conntrack original destination), not the container's.
  for port in $PORTS; do
    "$ipt" -w -C DOCKER-USER -i "$EXT_IF" -p tcp -m conntrack --ctorigdstport "$port" --ctdir ORIGINAL -j "$CHAIN" 2>/dev/null \
      || "$ipt" -w -I DOCKER-USER 1 -i "$EXT_IF" -p tcp -m conntrack --ctorigdstport "$port" --ctdir ORIGINAL -j "$CHAIN"
  done
  echo "$ipt: $(wc -l < "$ranges") Cloudflare ranges allowed on ports $PORTS via $EXT_IF."
}

remove_family() {
  local ipt="$1" port
  have_chain "$ipt" DOCKER-USER || return 0
  for port in $PORTS; do
    while "$ipt" -w -C DOCKER-USER -i "$EXT_IF" -p tcp -m conntrack --ctorigdstport "$port" --ctdir ORIGINAL -j "$CHAIN" 2>/dev/null; do
      "$ipt" -w -D DOCKER-USER -i "$EXT_IF" -p tcp -m conntrack --ctorigdstport "$port" --ctdir ORIGINAL -j "$CHAIN"
    done
  done
  if have_chain "$ipt" "$CHAIN"; then
    "$ipt" -w -F "$CHAIN"
    "$ipt" -w -X "$CHAIN"
  fi
  echo "$ipt: rules removed."
}

case "$cmd" in
  apply)
    [ -n "$EXT_IF" ] || { echo "Cannot find the public interface; set EXT_IF=eth0 (or similar)." >&2; exit 1; }
    tmp="$(mktemp -d)"; trap 'rm -rf "$tmp"' EXIT
    fetch_list "$URL4" '^[0-9]{1,3}(\.[0-9]{1,3}){3}/[0-9]{1,2}$' 8 32 > "$tmp/v4" \
      || { echo "Could not fetch a valid IPv4 list from $URL4; nothing changed." >&2; exit 1; }
    fetch_list "$URL6" '^[0-9a-fA-F:]+/[0-9]{1,3}$' 16 128 > "$tmp/v6" \
      || { echo "Could not fetch a valid IPv6 list from $URL6; nothing changed." >&2; exit 1; }
    [ "$(wc -l < "$tmp/v4")" -ge 5 ] && [ "$(wc -l < "$tmp/v6")" -ge 3 ] \
      || { echo "The lists look too short; nothing changed." >&2; exit 1; }
    install_family iptables "$tmp/v4"
    if command -v ip6tables >/dev/null 2>&1; then install_family ip6tables "$tmp/v6"; fi
    ;;
  status)
    for ipt in iptables ip6tables; do
      command -v "$ipt" >/dev/null 2>&1 || continue
      echo "== $ipt DOCKER-USER"; "$ipt" -w -S DOCKER-USER 2>&1 || true
      echo "== $ipt $CHAIN"; "$ipt" -w -S "$CHAIN" 2>&1 | head -n 40 || true
    done
    ;;
  remove)
    [ -n "$EXT_IF" ] || { echo "Cannot find the public interface; set EXT_IF=eth0 (or similar)." >&2; exit 1; }
    remove_family iptables
    command -v ip6tables >/dev/null 2>&1 && remove_family ip6tables
    ;;
  *)
    echo "Usage: $0 apply|status|remove" >&2
    exit 2
    ;;
esac
