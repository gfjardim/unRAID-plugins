#!/bin/bash

is_running()
{
  [ -e "/proc/${1}/exe" ] && return 0 || return 1;
}

stop_kill()
{
  if ! is_running $1; then return 0; fi 

  kill -s SIGINT $1

  for (( i = 0; i < $(( $2 * 5 )); i++ )); do
    if ! is_running $1; then
      return 0; 
    fi
    sleep 0.2
  done

  kill -s SIGKILL $1

  for (( i = 0; i < $(( $2 * 5 )); i++ )); do
    if ! is_running $1; then
      return 0; 
    fi
    sleep 0.2
  done
}

get_serial()
{
  attrs=$(udevadm info --query=property --name="${1}" 2>/dev/null)
  serial_number=$(echo -e "$attrs" | awk -F'=' '/ID_SCSI_SERIAL/{print $2}')
  if [ -z "$serial_number" ]; then
    echo $(echo -e "$attrs" | awk -F'=' '/ID_SERIAL_SHORT/{print $2}')
  fi
}


for dir in $(find /tmp/.preclear -mindepth 1 -maxdepth 1 -type d ); do
  pidfile="$dir/pid"

  if [ -f "$pidfile" ]; then
    pid=$(cat $pidfile)

    if ! is_running $pid; then continue; fi

    children=$(ps -o pid= --ppid $pid 2>/dev/null)
    stop_kill $pid 10

    for cpid in $children; do
      ppid=$(ps -o ppid= -p $cpid 2>/dev/null)
      if [ "$ppid" == "$pid" ]; then
        stop_kill $cpid 10
      fi
    done
  fi

  while read dd_pid; do
    stop_kill $dd_pid 10
  done < <( ps -o "%p|" -o "cmd:100" --no-headers -p $(pidof dd) | grep /dev/$(basename $dir) | cut -d '|' -f 1)

  rm -rf $dir
  tmux kill-session -t "preclear_disk_"$( get_serial $(basename $dir))
  rm -f "/tmp/preclear_stat_"$(basename $dir)
done
