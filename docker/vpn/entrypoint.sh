#!/usr/bin/env bash
set -euo pipefail

# =========================================================================
# VPN client L2TP/IPSec (dial-out) ke MikroTik.
# Render config dari env, bangun tunnel, tambahkan route ke subnet mesin,
# lalu monitor + reconnect. Container tetap foreground.
#
# Env wajib:
#   VPN_SERVER     : IP/host VPN server (mis. 202.138.226.231)
#   VPN_PSK        : pre-shared key IPsec
#   VPN_USERNAME   : user PPP/L2TP
#   VPN_PASSWORD   : password PPP/L2TP
# Env opsional:
#   VPN_ROUTES     : subnet via tunnel, pisah spasi (default "200.100.100.0/24")
# =========================================================================

: "${VPN_SERVER:?VPN_SERVER wajib diisi}"
: "${VPN_PSK:?VPN_PSK wajib diisi}"
: "${VPN_USERNAME:?VPN_USERNAME wajib diisi}"
: "${VPN_PASSWORD:?VPN_PASSWORD wajib diisi}"
VPN_ROUTES="${VPN_ROUTES:-200.100.100.0/24}"

log() { echo "[vpn] $*"; }

# --- /dev/ppp check ---
if [ ! -e /dev/ppp ]; then
    log "PERINGATAN: /dev/ppp tidak ada. Pastikan host: 'modprobe ppp_generic' dan"
    log "compose punya 'devices: - /dev/ppp:/dev/ppp' + cap_add NET_ADMIN."
fi

# --- IPsec (strongSwan / ipsec.conf gaya legacy stroke) ---
# Proposal disesuaikan MikroTik default L2TP: AES-CBC + SHA1 + DH modp1024/2048.
cat > /etc/ipsec.conf <<EOF
config setup
    charondebug="ike 1, knl 1, cfg 0"
    uniqueids=no

conn %default
    keyexchange=ikev1
    authby=secret
    ike=aes256-sha1-modp1024,aes128-sha1-modp1024,aes256-sha1-modp2048,3des-sha1-modp1024!
    esp=aes256-sha1,aes128-sha1,3des-sha1!
    dpdaction=restart
    dpddelay=15s

conn l2tp
    type=transport
    left=%defaultroute
    leftprotoport=17/1701
    right=${VPN_SERVER}
    rightprotoport=17/1701
    auto=start
EOF

cat > /etc/ipsec.secrets <<EOF
: PSK "${VPN_PSK}"
EOF
chmod 600 /etc/ipsec.secrets

# --- xl2tpd ---
mkdir -p /var/run/xl2tpd /etc/xl2tpd
cat > /etc/xl2tpd/xl2tpd.conf <<EOF
[global]
port = 1701

[lac vpn]
lns = ${VPN_SERVER}
ppp debug = no
pppoptfile = /etc/ppp/options.l2tpd.client
length bit = yes
EOF

# --- opsi ppp: JANGAN ambil default route (kita hanya route subnet mesin) ---
cat > /etc/ppp/options.l2tpd.client <<EOF
ipcp-accept-local
ipcp-accept-remote
refuse-eap
refuse-pap
refuse-chap
require-mschap-v2
noccp
noauth
mtu 1410
mru 1410
noipdefault
nodefaultroute
usepeerdns
connect-delay 5000
name ${VPN_USERNAME}
password ${VPN_PASSWORD}
EOF

add_routes() {
    for net in $VPN_ROUTES; do
        if ! ip route show | grep -q "$net"; then
            log "Menambah route $net via ppp0"
            ip route replace "$net" dev ppp0 || log "Gagal route $net (ppp0 belum siap?)"
        fi
    done
}

cleanup() {
    log "Shutdown: turunkan tunnel..."
    echo "d vpn" > /var/run/xl2tpd/l2tp-control 2>/dev/null || true
    ipsec stop 2>/dev/null || true
    pkill xl2tpd 2>/dev/null || true
    exit 0
}
trap cleanup TERM INT

start_tunnel() {
    log "Start IPsec..."
    ipsec start
    sleep 3
    log "Bangun SA IPsec ke ${VPN_SERVER}..."
    ipsec up l2tp || log "ipsec up l2tp gagal (akan dicoba ulang via loop)."

    log "Start xl2tpd..."
    (xl2tpd -D &)
    sleep 2

    log "Dial LAC l2tp..."
    echo "c vpn" > /var/run/xl2tpd/l2tp-control
}

ppp_up() { ip link show ppp0 >/dev/null 2>&1 && ip -4 addr show ppp0 | grep -q "inet "; }

start_tunnel

# Tunggu ppp0
log "Menunggu ppp0 naik..."
for i in $(seq 1 30); do
    if ppp_up; then break; fi
    sleep 2
done

if ppp_up; then
    log "ppp0 UP: $(ip -4 addr show ppp0 | awk '/inet /{print $2}')"
    add_routes
else
    log "ppp0 belum naik setelah 60s — loop monitor akan terus mencoba."
fi

# --- Monitor + reconnect ---
while true; do
    sleep 15
    if ! ppp_up; then
        log "ppp0 DOWN — reconnect..."
        echo "d vpn" > /var/run/xl2tpd/l2tp-control 2>/dev/null || true
        ipsec down l2tp 2>/dev/null || true
        sleep 3
        ipsec up l2tp 2>/dev/null || true
        sleep 2
        echo "c vpn" > /var/run/xl2tpd/l2tp-control 2>/dev/null || true
        sleep 8
        if ppp_up; then
            log "Reconnect sukses."
            add_routes
        fi
    else
        add_routes
    fi
done
