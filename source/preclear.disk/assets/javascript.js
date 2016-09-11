var PreclearURL = '/plugins/'+plugin+'/Preclear.php'

$(function()
  {
    var refresh = 10000;
    getPreclearContent();
  }
);


function getPreclearContent()
{
  $.post(PreclearURL,{action:'get_content',display:display},function(data)
  {
    if ( $('#preclear-table-body').length )
    {
      $( '#preclear-table-body' ).html( data.disks );
    }

    window.disksInfo = JSON.parse(data.info);

    if (typeof(startDisk) !== 'undefined')
    {
      startPreclear(startDisk);
      delete window.startDisk;
    }
  },'json').always(function()
  {
    setTimeout('getPreclearContent()', 10000);
  });
}


function openPreclear(device)
{
  var width   = 985;
  var height  = 730;
  var top     = (screen.height-height)/2;
  var left    = (screen.width-width)/2;
  var options = 'resizeable=yes,scrollbars=yes,height='+height+',width='+width+',top='+top+',left='+left;
  window.open('/plugins/'+plugin+'/Preclear.php?action=show_preclear&device='+device, 'Preclear', options);
}


function toggleScript(el, device)
{
  window.scope = $(el).val();
  $( "#preclear-dialog" ).dialog( "close" );
  startPreclear( device );
}


function startPreclear(device)
{
  if (typeof(device) === 'undefined')
  {
    return false;
  }

  var title = 'Start Preclear';
  $( "#preclear-dialog" ).html("<dl><dt>Model Family:</dt><dd style='margin-bottom:0px;'><span style='color:#EF3D47;font-weight:bold;'>"+getDiskInfo(device, 'family')+"</span></dd></dl>");
  $( "#preclear-dialog" ).append("<dl><dt>Device Model:</dt><dd style='margin-bottom:0px;'><span style='color:#EF3D47;font-weight:bold;'>"+getDiskInfo(device, 'model')+"</span></dd></dl>");
  $( "#preclear-dialog" ).append("<dl><dt>Serial Number:</dt><dd style='margin-bottom:0px;'><span style='color:#EF3D47;font-weight:bold;'>"+getDiskInfo(device, 'serial_short')+"</span></dd></dl>");
  $( "#preclear-dialog" ).append("<dl><dt>Firmware Version:</dt><dd style='margin-bottom:0px;'><span style='color:#EF3D47;font-weight:bold;'>"+getDiskInfo(device, 'firmware')+"</span></dd></dl>");
  $( "#preclear-dialog" ).append("<dl><dt>Size:</dt><dd style='margin-bottom:0px;'><span style='color:#EF3D47;font-weight:bold;'>"+getDiskInfo(device, 'size')+"</span></dd></dl><hr style='margin-left:12px;'>");

  if (typeof(scripts) !== 'undefined')
  {
    size = Object.keys(scripts).length;

    if (size)
    {
      var options = "<dl><dt>Script<st><dd><select onchange='toggleScript(this,\""+device+"\");'>";
      $.each( scripts, function( key, value )
        {
          var sel = ( key == scope ) ? "selected" : "";
          options += "<option value='"+key+"' "+sel+">"+authors[key]+"</option>";
        }
      );
      $( "#preclear-dialog" ).append(options+"</select></dd></dl>");

    }
  }

  $( "#preclear-dialog" ).append($("#"+scope+"-start-defaults").html());
  $( "#preclear-dialog" ).find(".switch").switchButton({labels_placement:"right",on_label:'YES',off_label:'NO'});
  $( "#preclear-dialog" ).find(".switch-button-background").css("margin-top", "6px");
  $( "#preclear-dialog" ).dialog({
    title: title,
    resizable: false,
    width: 600,
    modal: true,
    show : {effect: 'fade' , duration: 250},
    hide : {effect: 'fade' , duration: 250},
    buttons: {
      "Start": function(e)
      {
        $('button:eq(0)',$('#dialog_id').dialog.buttons).button('disable');
        $(e.target).attr('disabled', true);
        var opts       = new Object();
        opts["action"] = "start_preclear";
        opts["device"] = device;
        opts["op"]     = getVal(this, "op");
        opts["scope"]  = scope;

        if (scope == "joel")
        {
          opts["-c"]  = getVal(this, "-c");
          opts["-o"]  = getVal(this, "preclear_notify1") == "on" ? 1 : 0;
          opts["-o"] += getVal(this, "preclear_notify2") == "on" ? 2 : 0;
          opts["-o"] += getVal(this, "preclear_notify3") == "on" ? 4 : 0;
          opts["-M"]  = getVal(this, "-M");
          opts["-r"]  = getVal(this, "-r");
          opts["-w"]  = getVal(this, "-w");
          opts["-W"]  = getVal(this, "-W");
          opts["-f"]  = getVal(this, "-f");
        }

        else
        {
          opts["--cycles"]        = getVal(this, "--cycles");
          opts["--notify"]        = getVal(this, "preclear_notify1") == "on" ? 1 : 0;
          opts["--notify"]       += getVal(this, "preclear_notify2") == "on" ? 2 : 0;
          opts["--notify"]       += getVal(this, "preclear_notify3") == "on" ? 4 : 0;
          opts["--frequency"]     = getVal(this, "--frequency");
          opts["--read-size"]     = getVal(this, "--read-size");
          opts["--skip-preread"]  = getVal(this, "--skip-preread");
          opts["--skip-postread"] = getVal(this, "--skip-postread");        
        }

        $.post(PreclearURL, opts, function(data)
                {
                  openPreclear(device);
                }
              ).always(function(data)
                {
                  window.location=window.location.pathname+window.location.hash;
                }
              );
        $( this ).dialog( "close" );
      },
      Cancel: function()
      {
        $( this ).dialog( "close" );
      }
    }
  });
}


function stopPreclear(serial, device, ask)
{
  var title = 'Stop Preclear';
  var exec  = '$.post(PreclearURL,{action:"stop_preclear",device:device});'

  if (ask != "ask")
  {
    eval(exec);
    window.location=window.location.pathname+window.location.hash;
    return true;
  }

  $( "#preclear-dialog" ).html('Disk: '+serial);
  $( "#preclear-dialog" ).append( "<br><br><span style='color: #E80000;'>Are you sure?</span>" );
  $( "#preclear-dialog" ).dialog({
    title: title,
    resizable: false,
    width: 500,
    modal: true,
    show : {effect: 'fade' , duration: 250},
    hide : {effect: 'fade' , duration: 250},
    buttons: {
      "Stop": function()
      {
        eval(exec);
        $( this ).dialog( "close" );
        window.location=window.location.pathname+window.location.hash;
      },
      Cancel: function()
      {
        $( this ).dialog( "close" );
      }
    }
  });
}


function getVal(el, name)
{
  el = $(el).find("*[name="+name+"]");
  return value = ( $(el).attr('type') == 'checkbox' ) ? ($(el).is(':checked') ? "on" : "off") : $(el).val();
}


function toggleSettings(el) {
  if ( el.selectedIndex > 0 && el.selectedIndex != 1 )
  {
    $(el).parent().siblings('.clear_options').css('display','none');
    $(el).parent().siblings('.test_options').css('display','none');
    $(el).parent().siblings('.clear_verify_options').css('display','none');
  }

  else if ( el.selectedIndex == 1 )
  {
    $(el).parent().siblings('.clear_options').css('display','none');
    $(el).parent().siblings('.test_options').css('display','none');
    $(el).parent().siblings('.clear_verify_options').css('display','block');
  }

  else
  {
    $(el).parent().siblings('.clear_options').css('display','block');
    $(el).parent().siblings('.test_options').css('display','block');
    $(el).parent().siblings('.clear_verify_options').css('display','block');
  }
}


function toggleFrequency(el, name) {
  var disabled = true;
  var sel      = $(el).parent().parent().find("select[name='"+name+"']");
  $(el).siblings("*[type='checkbox']").addBack().each(function(v, e)
    {
      if ($(e).is(':checked'))
      {
        disabled = false;
      }
    }
  );

  if (disabled) {
    sel.attr('disabled', 'disabled');
  } else {
    sel.removeAttr('disabled');
  }
}


function toggleNotification(el) {
  if(el.selectedIndex > 0 )
  {
    $(el).parent().siblings('.notification_options').css('display','block');
  }

  else
  {
    $(el).parent().siblings('.notification_options').css('display','none');
  }
}


function getDiskInfo(device, info){
  for (var i = disksInfo.length - 1; i >= 0; i--) {
    if (disksInfo[i]['device'].indexOf(device) > -1 ){
      return disksInfo[i][info];
    }
  }
}
