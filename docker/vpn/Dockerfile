# ADMS Middleware — VPN client L2TP/IPSec (dial-out ke MikroTik).
# Container ini memegang tunnel; service `nginx` & `queue` berbagi network
# namespace-nya (network_mode: service:vpn).
FROM debian:bookworm-slim

RUN apt-get update && apt-get install -y --no-install-recommends \
        strongswan \
        xl2tpd \
        ppp \
        iproute2 \
        iputils-ping \
        procps \
        netcat-openbsd \
    && rm -rf /var/lib/apt/lists/*

COPY docker/vpn/entrypoint.sh /usr/local/bin/vpn-entrypoint
RUN chmod +x /usr/local/bin/vpn-entrypoint

ENTRYPOINT ["vpn-entrypoint"]
