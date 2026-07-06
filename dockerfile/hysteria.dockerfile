FROM tobyxdd/hysteria
RUN apk add --no-cache openssh jq \
    && mkdir -p /root/.ssh
ENV ENV="/root/.ashrc"
ENTRYPOINT []