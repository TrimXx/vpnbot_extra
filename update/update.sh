#!/bin/bash
pwd=`pwd`
process_name="$pwd/update/update.sh"
current_pid=$$
pids=$(pgrep -f $process_name)
for pid in $pids; do
    if [ $pid -ne $current_pid ]; then
        kill -9 $pid
    fi
done

> $pwd/update/pipe
echo "$$" > $pwd/update/update_pid

while true
do
    cmd=$(cat $pwd/update/pipe)
    branch=$(cat $pwd/update/branch 2>/dev/null)
    if [[ -n "$cmd" ]]
    then
        > $pwd/update/pipe
        if [[ "$cmd" == "node" ]]
        then
            node_branch=$(cat $pwd/update/node_pull_branch 2>/dev/null)
            if [[ -z "$node_branch" ]]
            then
                node_branch="v2"
            fi
            bash "$pwd/scripts/node_remote_update.sh" "$node_branch" > "$pwd/update/message" 2>&1
            > $pwd/update/node_pull_branch
            bash $pwd/update/update.sh &
            exit 0
        fi
        key=$(cat $pwd/update/key)
        curl -H "Content-Type: application/json" -X POST https://api.telegram.org/bot$key/editMessageText -d "$(cat $pwd/update/curl | sed 's/"text":"~t~"/"text": "stopping the bot"/')" 2>/dev/null || true
        docker compose down --remove-orphans
        if [[ "$cmd" == "1" ]]
        then
            curl -H "Content-Type: application/json" -X POST https://api.telegram.org/bot$key/editMessageText -d "$(cat $pwd/update/curl | sed 's/"text":"~t~"/"text": "clearing the directory"/')" 2>/dev/null || true
            curl -H "Content-Type: application/json" -X POST https://api.telegram.org/bot$key/editMessageText -d "$(cat $pwd/update/curl | sed 's/"text":"~t~"/"text": "downloading the update"/')" 2>/dev/null || true
            git fetch
            if [[ -n "$branch" ]]
            then
                curl -H "Content-Type: application/json" -X POST https://api.telegram.org/bot$key/editMessageText -d "$(cat $pwd/update/curl | sed 's/"text":"~t~"/"text": "changing branch"/')" 2>/dev/null || true
                git checkout -t origin/$branch || git checkout $branch
            fi
            curl -H "Content-Type: application/json" -X POST https://api.telegram.org/bot$key/editMessageText -d "$(cat $pwd/update/curl | sed 's/"text":"~t~"/"text": "applying updates"/')" 2>/dev/null || true
            git checkout origin/$(git rev-parse --abbrev-ref HEAD) -- app update makefile version > ./update/message 2>&1
        fi
        curl -H "Content-Type: application/json" -X POST https://api.telegram.org/bot$key/editMessageText -d "$(cat $pwd/update/curl | sed 's/"text":"~t~"/"text": "launching the bot"/')" 2>/dev/null || true
        > $pwd/update/key
        > $pwd/update/curl
        VER=$(awk 'NR==1{for(i=1;i<=NF;i++) if($$i ~ /^v[0-9]/){print $$i; exit}}' version)
        if [[ -z "$VER" ]]; then
            VER="local"
        fi
        IP=$(hostname -I | awk '{print $1}') VER=$VER docker compose --env-file ./.env --env-file ./override.env up -d --force-recreate
        bash $pwd/update/update.sh &
        exit 0
    fi
    sleep 1
done
