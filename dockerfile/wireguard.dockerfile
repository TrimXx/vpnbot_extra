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

FROM $image
COPY --from=awg-build /out/usr/ /usr/
RUN apk add --no-cache iproute2 iptables xtables-addons openssh wireguard-tools jq bash htop \
    && mkdir -p /root/.ssh /etc/amnezia/amneziawg
