ARG image
FROM golang:1.24.4-alpine AS awg-build
RUN apk add --no-cache git make build-base linux-headers
RUN git clone --depth 1 https://github.com/amnezia-vpn/amneziawg-go.git /src/amneziawg-go \
    && cd /src/amneziawg-go \
    && make \
    && DESTDIR=/out make install
RUN git clone --depth 1 --branch v1.0.20260223 https://github.com/amnezia-vpn/amneziawg-tools.git /src/amneziawg-tools \
    && cd /src/amneziawg-tools/src \
    && make \
    && DESTDIR=/out WITH_WGQUICK=yes make install

FROM alpine:3.21 AS microsocks-build
RUN apk add --no-cache git make build-base \
    && git clone --depth 1 https://github.com/rofl0r/microsocks.git /src/microsocks \
    && make -C /src/microsocks

FROM $image
COPY --from=awg-build /out/usr/ /usr/
COPY --from=microsocks-build /src/microsocks/microsocks /usr/bin/microsocks
RUN apk add --no-cache iproute2 iptables xtables-addons openssh wireguard-tools jq bash htop curl \
    && mkdir -p /root/.ssh /etc/amnezia/amneziawg /etc/warp \
    && curl -fsSL -o /usr/bin/wgcf https://github.com/ViRb3/wgcf/releases/download/v2.2.22/wgcf_2.2.22_linux_amd64 \
    && chmod +x /usr/bin/wgcf /usr/bin/microsocks \
    && sed -i 's/sysctl -q net\.ipv4\.conf\.all\.src_valid_mark=1/echo "skip sysctl src_valid_mark"/' "$(which wg-quick)"
