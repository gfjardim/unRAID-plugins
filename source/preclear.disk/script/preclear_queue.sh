#!/bin/bash

debug() {
  local msg="$*"
  if [ -z "$msg" ]; then
    read msg;
  fi
  cat <<< "$(date +"%b %d %T" ) preclear_queue: $msg" >> /var/log/preclear.disk.log
}

get_running_sessions() {
  for file in $(ls -tr /tmp/.preclear/*/pid 2>/dev/null); do
    pid=$(cat $file);
    if [ -e "/proc/${pid}/exe" ]; then
      echo $(echo $file | cut -d'/' -f4);
    fi
  done
}

do_clean()
{
  for disk in $(get_running_sessions); do 
    paused="/tmp/.preclear/$disk/pause"
    if [ -f "$paused" ]; then
      rm "/tmp/.preclear/$disk/pause" 2>/dev/null;
      debug "Continuing $disk preclear session"
    fi
  done
  rm /var/run/preclear_queue.pid
  rm /var/state/preclear_queue
  debug "Stopped"
}

queue=${1-1};

echo $$ > /var/run/preclear_queue.pid
echo $queue > /var/state/preclear_queue
debug "Start queue with $queue slots"

trap "do_clean;" exit

while [ -f /var/run/preclear_queue.pid ]; do
  i=0
  for disk in $(get_running_sessions); do
    tmpdir="/tmp/.preclear/${disk}"
    paused="${tmpdir}/pause"
    if [ -d $tmpdir ]; then
      if [ $i -lt $queue -a -f $paused ]; then
        debug "Continuing $disk preclear session"
        rm $paused
      elif [ $i -ge $queue -a ! -f $paused ]; then
        debug "Pausing $disk preclear session"
        touch $paused
      fi
    fi
    i=$(( $i + 1 ))
  done
  sleep 1
done