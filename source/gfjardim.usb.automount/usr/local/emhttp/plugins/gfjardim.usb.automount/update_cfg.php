<?
$plugin = "gfjardim.usb.automount";
require_once("/usr/local/emhttp/plugins/${plugin}/include/usb_mount_lib.php");

if ($_POST['action'] == "automount" ){
  $serial = urldecode(($_POST['serial']));
  $status = urldecode(($_POST['status']));
  echo json_encode(array( 'automount' => toggle_automount($serial, $status) ));
}
?>