#!/bin/sh
set -eu

REPO_URL="https://github.com/TrimXx/vpnbot_extra.git"
LEGACY_DIR="/root/vpnbot"
DEFAULT_DIR="/root/vpnbot_extra"
UPGRADE_SCOPE="${UPGRADE_SCOPE:-app}"
if [ -z "${APP_DIR:-}" ]; then
    if [ -d "$LEGACY_DIR/.git" ] && [ ! -d "$DEFAULT_DIR/.git" ]; then
        APP_DIR="$LEGACY_DIR"
    else
        APP_DIR="$DEFAULT_DIR"
    fi
fi

BOT_KEY=""
TAG="master"
ARG1="${1:-}"
ARG2="${2:-}"
if [ -n "$ARG1" ]; then
    case "$ARG1" in
        *:*)
        BOT_KEY="$ARG1"
        TAG="${ARG2:-master}"
        ;;
        *)
        TAG="$ARG1"
        ;;
    esac
fi

echo "[vpnbot] Installing dependencies..."
apt update
apt install -y \
    ca-certificates \
    curl \
    gnupg \
    lsb-release \
    make \
    git \
    iptables \
    iproute2 \
    xtables-addons-common \
    xtables-addons-dkms

if ! command -v docker >/dev/null 2>&1; then
    echo "[vpnbot] Docker not found, installing..."
    curl -fsSL https://get.docker.com -o /tmp/get-docker.sh
    sh /tmp/get-docker.sh
fi

if [ -d "$APP_DIR/.git" ]; then
    echo "[vpnbot] Existing installation detected in $APP_DIR"
    cd "$APP_DIR"
    git remote set-url origin "$REPO_URL"
    git fetch --tags origin
    if [ "$UPGRADE_SCOPE" = "all" ]; then
        if [ -n "$(git status --porcelain)" ]; then
            STASH_NAME="vpnbot-init-$(date +%s)"
            echo "[vpnbot] Local changes detected, saving to stash: $STASH_NAME"
            git stash push --include-untracked -m "$STASH_NAME" >/dev/null
        fi
        git checkout "$TAG"
        git pull --ff-only origin "$TAG"
    else
        echo "[vpnbot] App-only upgrade mode (default): updating ./app from origin/$TAG"
        git checkout "origin/$TAG" -- app
    fi
else
    echo "[vpnbot] Fresh install to $APP_DIR"
    if [ -z "$BOT_KEY" ]; then
        echo "[vpnbot] ERROR: BOT_KEY is required for fresh install."
        echo "Fresh install: wget -O- <init.sh> | sh -s <YOUR_TELEGRAM_BOT_KEY> [branch]"
        echo "Upgrade only: wget -O- <init.sh> | sh -s -- [branch]"
        exit 1
    fi
    git clone "$REPO_URL" "$APP_DIR"
    cd "$APP_DIR"
    git checkout "$TAG"
fi

if [ ! -f "./app/config.php" ]; then
    if [ -z "$BOT_KEY" ]; then
        echo "[vpnbot] ERROR: app/config.php is missing and BOT_KEY was not provided."
        echo "Run again with key: sh -s <YOUR_TELEGRAM_BOT_KEY> [branch]"
        exit 1
    fi
    cat > ./app/config.php <<EOF
<?php

\$c = ['key' => '$BOT_KEY'];
EOF
fi

echo "[vpnbot] Starting/Updating containers..."
make u
echo "[vpnbot] Done."
