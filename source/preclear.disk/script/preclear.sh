#!/bin/bash

while read i; do 
  [ -n "$output" ] && echo -e "\n$(printf '=-%.0s' {1..62})\n" || output=y
  tmux capture-pane -t "$i"
  tmux show-buffer | sed '/^$/{:a;N;s/\n$//;ta}';
done <<<"$( tmux ls | awk 'BEGIN { FS=":" } /^preclear_disk/ {print $1}' )"