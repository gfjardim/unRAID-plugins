<?PHP
/* Copyright 2005-2017, Lime Technology
 * Copyright 2012-2017, Bergware International.
 * Copyright 2012-2018, gfjardim.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 */
?>
<?
ob_implicit_flush();
set_time_limit(0);

$disk = $_POST["disk"] ? $_POST["disk"] : $argv[1]; 
?>
<head>
<?if (! count($disk)):?>
<script src='https://cdn.rawgit.com/eBay/jsonpipe/e75e3b58/jsonpipe.js'></script>
<?if (is_file("webGui/scripts/dynamix.js")):?>
<script type='text/javascript' src='/webGui/scripts/dynamix.js'></script>
<?else:?>
<script type='text/javascript' src='/webGui/javascript/dynamix.js'></script>
<?endif;?>
<?
$docroot = $docroot ?? $_SERVER['DOCUMENT_ROOT'] ?: '/usr/local/emhttp';
require_once "$docroot/webGui/include/ColorCoding.php";

$logging = file("$docroot/logging.htm", FILE_IGNORE_NEW_LINES);
$initOffset   = array_keys(preg_grep("/^<style>/i", $logging))[0];
$endOffset    = array_keys(preg_grep('/^<\/style>/i', array_slice($logging, $initOffset) ))[0];
$style = array_slice($logging, $initOffset, $endOffset + 1 );
echo implode(PHP_EOL, $style);

?>
<script type="text/javascript">
function indexesOf(string, regex) {
    var match,
        indexes = [];

    regex = new RegExp(regex);

    while (match = regex.exec(string)) {
        if (!indexes[match[0]]) indexes[match[0]] = [];
        indexes.push(match.index);
    }

    return indexes;
}
$(function()
{
  var curr_index = 0;
  var last_index = 0;

  pi = 0;
  ps = 0;
  $.ajax({
    type: 'POST',
    url: "/plugins/preclear.disk/script/preclear_log.php", // arquivo de envio dos emails
    data: {"disk": "sdp", "csrf_token": "9DB26ECD1CF5F2C6"},
    xhrFields: {
      onprogress: function(e) {
        var data = e.currentTarget.responseText;
        var indexes = indexesOf(data, /<br>/g);
        $.each(indexes, function( index, value )
        {
          if (index > last_index)
          {
            if (index >= indexes.length)
            {
              initial = indexes[index - 1];
              final = value;
            }
            else
            {
              initial = indexes[index - 1];
              final = value;              
            }
            elm = $(data.substring(initial , final));
            if (elm.hasClass("syslog"))
            {
              elm.appendTo("p#syslog");
              ps += 1;
            }
            else
            {
              elm.appendTo("p#plugin");
              pi += 1;
            }
            last_index = index;
          }
        });

      }
    }
  });
});
</script>
</head>
<body class="logLine spacing">
<p style='text-align:center'><span class='error label'>Error</span><span class='warn label'>Warning</span><span class='system label'>System</span><span class='array label'>Array</span><span class='login label'>Login</span></p>
<p id="syslog">
  <span class='system label'>SYSLOG</span>
</p>
<p id="plugin">
  <span class='system label'>PLUGIN</span>
</p>
</body>
<?exit(0);?>
<?endif;?>
<?

$disk = $_POST["disk"] ? $_POST["disk"] : $argv[1];
$docroot = $docroot ?? $_SERVER['DOCUMENT_ROOT'] ?: '/usr/local/emhttp';
require_once "$docroot/webGui/include/ColorCoding.php";

$ata = exec("ls -n  ".escapeshellarg("/sys/block/${disk}")."|grep -Po 'ata\d+'");
$dev = $ata ? "${disk}|${ata}[.:]" : $disk;
$logs = glob("/var/log/syslog.*");
$serial = exec("udevadm info --query=property --name=".escapeshellarg($disk)." | grep -Po '(?:ID_SCSI_SERIAL|ID_SERIAL_SHORT)=\K.*'");

foreach ($logs as $syslog) {
  exec("grep -P ".escapeshellarg($dev)." ".escapeshellarg($syslog), $lines);
  foreach ($lines as $line) {
    if (strpos($line,'disk_log')!==false) continue;
    $span = "span";
    foreach ($match as $type) foreach ($type['text'] as $text) if (preg_match("/$text/i",$line)) {$span = "span class='{$type['class']}'"; break 2;}
    echo "<$span>".htmlspecialchars($line)."</span>";
   ob_flush();
   flush();
  }
  unset($lines);
}

$handler1 = popen("/usr/bin/tail -n +1 -f /var/log/syslog", 'r');
stream_set_blocking($handler1, false);
$handler2 = popen("/usr/bin/tail -n +1 -f /var/log/preclear.disk.log", 'r');
stream_set_blocking($handler2, false);

while(connection_aborted() == 0)
{

  $line = fgets($handler1);
  if ($line !== false)
  {
    if (strpos($line, $dev)==false) continue;
    if (strpos($line,'tail_log')!==false) continue;
    if (strpos($line,'disk_log')!==false) continue;
    $span = "span class='syslog'";
    foreach ($match as $type) foreach ($type['text'] as $text) if (preg_match("/$text/i",$line)) {$span = "span class='{$type['class']} syslog'"; break 2;}
    echo "<$span>".htmlspecialchars(trim($line))."</span><br>";
  }

  $line = fgets($handler2);
  if ($line !== false)
  {
    if (strpos($line, $serial)==false) continue;
    $span = "span class='plugin'";
    foreach ($match as $type) foreach ($type['text'] as $text) if (preg_match("/$text/i",$line)) {$span = "span class='{$type['class']} plugin' "; break 2;}
    echo "<$span>".htmlspecialchars(trim($line))."</span><br>";
  }
  sleep(0.1);
}
pclose(handler1);
pclose(handler2);

?>