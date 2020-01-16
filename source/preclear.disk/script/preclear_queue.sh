#!/bin/bash

debug() {
  local msg="$*"
  if [ -z "$msg" ]; then
    read msg;
  fi
  cat <<< "$(date +"%b %d %T" ) preclear_queue: $msg" >> /var/log/preclear.disk.log
}

# Redirect errors to log
exec 2> >(while read err; do echo "$(date +"%b %d %T" ) preclear_queue: ${err}" >> /var/log/preclear.disk.log; echo "${err}"; done; >&2)

get_running_sessions() {
  for file in $(ls /tmp/.preclear/*/pid 2>/dev/null); do
    pid=$(cat $file);
    if [ -e "/proc/${pid}/exe" ]; then
      echo $(echo $file | cut -d'/' -f4);
    fi
  done
}

do_clean()
{
  for disk in $(get_running_sessions); do 
    queued="/tmp/.preclear/$disk/queued"
    if [ -f "$queued" ]; then
      rm "$queued" 2>/dev/null;
      debug "Restoring $disk preclear session"
    fi
  done
  rm /var/run/preclear_queue.pid 2>/dev/null
  debug "Stopped"
  tmux kill-window -t 'preclear_queue' 2>/dev/null
}

sort_running()
{
  local sort_file="/boot/config/plugins/preclear.disk/sort_order"
  local  -g -a sort_order
  local i=999999
  if [ -f "$sort_file" ]; then
    for disk in $(get_running_sessions); do
      local line=""
      if [ -f "$sort_file" ]; then
        line=$(awk "/$disk/{ print NR; exit }" $sort_file)
      fi
      if [ -n "$line" ]; then
        sort_order[$line]=$disk
      else
        sort_order[$i]=$disk
        i=$((i+1))
      fi
    done
    for disk in "${sort_order[@]}"; do
      echo $disk
    done
  else
    get_running_sessions
  fi
}

queue=${1-1};
timer=$(date '+%s')

echo $$ > /var/run/preclear_queue.pid
[ $queue -gt 1 ] && debug "Start queue with $queue slots" || debug "Start queue with $queue slot"

trap "do_clean;" exit

while [ -f /var/run/preclear_queue.pid ]; do
  i=0
  for disk in $(sort_running); do
    tmpdir="/tmp/.preclear/${disk}"
    queued="${tmpdir}/queued"
    paused="${tmpdir}/pause"
    if [ -d $tmpdir -a ! -f $paused ]; then
      if [ $i -lt $queue -a -f $queued ]; then
        debug "Restoring $disk preclear session"
        rm $queued
      elif [ $i -ge $queue -a ! -f $queued ]; then
        debug "Enqueuing $disk preclear session"
        touch $queued
      fi
      i=$(( $i + 1 ))
    fi
    timer=$(date '+%s')
  done
  if [ "$i" -eq "0" ] && [[  $(( $(date '+%s') - $timer )) -gt 60 ]]; then
    debug "No active jobs, stopping queue manager"
    break
  fi
  sleep 1
done