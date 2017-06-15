var PreclearURL = '/plugins/'+plugin+'/Preclear.php'

if (! $.prototype.tooltipsters)
{
  $("<link rel='stylesheet' type='text/css' href='/plugins/"+plugin+"/assets/tooltipster.bundle.hibrid.css'>").appendTo("head");
  $("<script type='text/javascript' src='/plugins/"+plugin+"/assets/tooltipster.bundle.min.js'>").appendTo("head");
}

$('body').on('mouseenter', '.tooltip:not(.tooltipstered), .tooltip-toggle:not(.tooltipstered)', function()
{
  onClose = {click:true, scroll:true, mouseleave:true, tap:true};
  if ( $(this).hasClass("tooltip-toggle") )
  {
    onClose.click = false;
  }
  $(this).tooltipster(
  {
    delay:100,
    zIndex:100,
    trigger:'custom',
    triggerOpen:{mouseenter:true, touchstart:true},
    triggerClose:onClose,
  }).tooltipster('open');
});

$(function()
  {
    getPreclearContent();
    if ( $('#usb_devices_list').length )
    {
      $('#usb_devices_list').bind("change", function(e)
      {
        clearTimeout(timers.preclear);
        timers.preclear = setTimeout('getPreclearContent()', 100);
      });
      $('#usb_devices_list').trigger("change");
    }
  }
);


function getPreclearContent()
{
  clearTimeout(timers.preclear);
  $.post(PreclearURL,{action:'get_content',display:display},function(data)
  {
    var hovered = $( ".tooltip:hover" ).map(function(){return this.id;}).get();
    if ( $('#preclear-table-body').length )
    {
      var target = $( '#preclear-table-body' );
      var opened = [];
      currentScroll  = $(window).scrollTop();
      currentToggled = getToggledReports();
      target.html( data.disks );
      toggleReports(currentToggled);
      $(window).scrollTop(currentScroll);
    }
    else
    {
      $.each(data.status, function(i,v)
      {
        var target = $("#preclear_"+i);
        var opened = [];
        target.find(".tooltip:hover").each(function(){ opened.push($(this).attr("id")); });
        $("#preclear_"+i).html("<i class='glyphicon glyphicon-dashboard hdd'></i><span style='margin:6px;'></span>"+v);
      });
    }
    $.each(hovered, function(k,v){ if(v.length) { $("#"+v).trigger("mouseenter");} });

    window.disksInfo = JSON.parse(data.info);

    if (typeof(startDisk) !== 'undefined')
    {
      startPreclear(startDisk);
      delete window.startDisk;
    }
  },'json').always(function()
  {
    timers.preclear = setTimeout('getPreclearContent()', 10000);
  });
}


function openPreclear(serial)
{
  var width   = 985;
  var height  = 730;
  var top     = (screen.height-height)/2;
  var left    = (screen.width-width)/2;
  var options = 'resizeable=yes,scrollbars=yes,height='+height+',width='+width+',top='+top+',left='+left;
  window.open('/plugins/'+plugin+'/Preclear.php?action=show_preclear&serial='+serial, 'Preclear', options);
}


function toggleScript(el, serial)
{
  window.scope = $(el).val();
  $( "#preclear-dialog" ).dialog( "close" );
  startPreclear( serial );
}


function startPreclear(serial)
{
  if (typeof(serial) === 'undefined')
  {
    return false;
  }

  var title = 'Start Preclear';
  $( "#preclear-dialog" ).html("<dl><dt>Model Family:</dt><dd style='margin-bottom:0px;'><span style='color:#EF3D47;font-weight:bold;'>"+getDiskInfo(serial, 'FAMILY')+"</span></dd></dl>");
  $( "#preclear-dialog" ).append("<dl><dt>Device Model:</dt><dd style='margin-bottom:0px;'><span style='color:#EF3D47;font-weight:bold;'>"+getDiskInfo(serial, 'MODEL')+"</span></dd></dl>");
  $( "#preclear-dialog" ).append("<dl><dt>Serial Number:</dt><dd style='margin-bottom:0px;'><span style='color:#EF3D47;font-weight:bold;'>"+getDiskInfo(serial, 'SERIAL_SHORT')+"</span></dd></dl>");
  $( "#preclear-dialog" ).append("<dl><dt>Firmware Version:</dt><dd style='margin-bottom:0px;'><span style='color:#EF3D47;font-weight:bold;'>"+getDiskInfo(serial, 'FIRMWARE')+"</span></dd></dl>");
  $( "#preclear-dialog" ).append("<dl><dt>Size:</dt><dd style='margin-bottom:0px;'><span style='color:#EF3D47;font-weight:bold;'>"+getDiskInfo(serial, 'SIZE_H')+"</span></dd></dl><hr style='margin-left:12px;'>");

  if (typeof(scripts) !== 'undefined')
  {
    size = Object.keys(scripts).length;

    if (size)
    {
      var options = "<dl><dt>Script<st><dd><select onchange='toggleScript(this,\""+serial+"\");'>";
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
        // $('button:eq(0)',$('#dialog_id').dialog.buttons).button('disable');
        $(e.target).attr('disabled', true);
        var opts       = new Object();
        opts["action"] = "start_preclear";
        opts["device"] = getDiskInfo(serial, 'DEVICE');
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
          opts["-s"]  = getVal(this, "-s");
        }

        else
        {
          opts["--cycles"]        = getVal(this, "--cycles");
          opts["--notify"]        = getVal(this, "preclear_notify1") == "on" ? 1 : 0;
          opts["--notify"]       += getVal(this, "preclear_notify2") == "on" ? 2 : 0;
          opts["--notify"]       += getVal(this, "preclear_notify3") == "on" ? 4 : 0;
          opts["--frequency"]     = getVal(this, "--frequency");
          opts["--skip-preread"]  = getVal(this, "--skip-preread");
          opts["--skip-postread"] = getVal(this, "--skip-postread");      
          opts["--test"]          = getVal(this, "--test");      
        }

        $.post(PreclearURL, opts, function(data)
                {
                  openPreclear(serial);
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


function stopPreclear(serial, ask)
{
  var title = 'Stop Preclear';
  var exec  = '$.post(PreclearURL,{action:"stop_preclear",serial:"'+serial+'"}).always(function(){window.location=window.location.pathname+window.location.hash});'
  var model = getDiskInfo(serial,"SERIAL");

  if (ask != "ask")
  {
    eval(exec);
    ;
    return true;
  }

  $( "#preclear-dialog" ).html('Disk: ' + model);
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
  var value = $(el).val();
  switch(value)
  {
    case '0':
    case '--erase-clear':
      $(el).parent().siblings('.read_options').css('display',    'block');
      $(el).parent().siblings('.write_options').css('display',   'block');
      $(el).parent().siblings('.postread_options').css('display','block');
      $(el).parent().siblings('.notify_options').css('display',  'block');
      break;

    case '--verify':
    case '--signature':
    case '-V':
      $(el).parent().siblings('.write_options').css('display',   'none');
      $(el).parent().siblings('.read_options').css('display',    'block');
      $(el).parent().siblings('.postread_options').css('display','block');
      $(el).parent().siblings('.notify_options').css('display',  'block');
      break;

    case '--erase':
      $(el).parent().siblings('.write_options').css('display',   'none');
      $(el).parent().siblings('.read_options').css('display',    'block');
      $(el).parent().siblings('.postread_options').css('display','block');
      $(el).parent().siblings('.notify_options').css('display',  'block');
      $(el).parent().siblings('.cycles_options').css('display',  'block');
      break;

    case '-t':
    case '-C 64':
    case '-C 63':
    case '-z':
      $(el).parent().siblings('.read_options').css('display',    'none');
      $(el).parent().siblings('.write_options').css('display',   'none');
      $(el).parent().siblings('.postread_options').css('display','none');
      $(el).parent().siblings('.notify_options').css('display',  'none');
      break;

    default:
      $(el).parent().siblings('.read_options').css('display',    'block');
      $(el).parent().siblings('.write_options').css('display',   'block');
      $(el).parent().siblings('.postread_options').css('display','block');
      $(el).parent().siblings('.notify_options').css('display',  'block');
      break;
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


function getDiskInfo(serial, info){
  for(key in disksInfo)
  {
    disk = disksInfo[key];
    if(disk.hasOwnProperty('SERIAL_SHORT') && disk['SERIAL_SHORT'] == serial)
    {
      return disk[info];
    }
  }
}


function toggleReports(opened)
{
  $(".toggle-reports").each(function()
  {
    var elem = $(this);
    var disk = elem.attr("hdd");
    elem.disableSelection();

    elem.click(function()
    {
      var elem = $(this);
      var disk = elem.attr("hdd");
      $(".toggle-"+disk).slideToggle(150, function()
      {
        if ( $("div.toggle-"+disk+":first").is(":visible") )
        {
          elem.find(".glyphicon-append").addClass("glyphicon-minus-sign").removeClass("glyphicon-plus-sign");
        }
        else
        {
          elem.find(".glyphicon-append").removeClass("glyphicon-minus-sign").addClass("glyphicon-plus-sign");
        }
      });
    });

    if (typeof(opened) !== 'undefined')
    {
      if ( $.inArray(disk, opened) > -1 )
      {
        $(".toggle-"+disk).css("display","block");
        elem.find(".glyphicon-append").addClass("glyphicon-minus-sign").removeClass("glyphicon-plus-sign");
      }
    }      
  });
}


function getToggledReports()
{ 
  var opened = [];
  $(".toggle-reports").each(function(e)
  {
    var elem = $(this);
    var disk = elem.attr("hdd");
    if ( $("div.toggle-"+disk+":first").is(":visible") )
    {
      opened.push(disk);
    }
  });
  return opened;
}

function rmReport(file, el)
{
  $.post(PreclearURL, {action:"remove_report", file:file}, function(data)
  {
    if (data)
    {
      var remain = $(el).closest("div").siblings().length;
      if ( remain == "0")
      {
        $(el).closest("td").find(".glyphicon-minus-sign, .glyphicon-plus-sign").css("opacity", "0.0");
      }
      $(el).parent().remove();
    }

  });
}

function get_tab_title_by_name(name) {
  var tab    = $("input[name$=tabs] + label").filter(function(){return $(this).text() === name;}).prev();
  var title  = $("div#title > span.left"    ).filter(function(){return $(this).text() === name;}).parent();
  if (tab.length) {
    return tab
  } else if (title.length) {
    return title
  } else {
    return $(document)
  }
}


function addButtonTab(Button, Name, autoHide, Append)
{
  if (typeof(autoHide) == "undefined") autoHide = true;
  if (typeof(Append)   == "undefined") Append   = true;

  var Target    = get_tab_title_by_name(Name);
  var elementId = 'event-' + new Date().getTime() * Math.floor(Math.random()*100000);
  var element   = $("<span id='"+elementId+"' class='status' style='padding-left:5px;'>"+Button+"</span>");
  
  if (element.find("input[type='button']").length)
  {
    element.addClass("vhshift");
    element.find("input[type='button']").prop("style","padding-top: 5px; padding-bottom: 5px;");
  }

  if (Target.prop('nodeName') === "DIV")
  {
    if (Append)
    {
      Target.append(element);
    }
    else
    {
      Target.prepend(element);
    }
  }
  else if (Target.prop('nodeName') === "INPUT")
  {
    element.css("display","none");

    if (Append)
    {
      $('.tabs').append(element);
    }
    else
    {
      $('.tabs').prepend(element);
    }

    Target.bind({click:function(){$('#'+elementId).fadeIn('slow');}});

    if (Target.is(':checked') || ! autoHide) {
      $('#'+elementId).fadeIn('slow');
    }

    $("input[name$=tabs]").each(function()
    {
      if (! $(this).is(Target) && autoHide )
      {
        $(this).bind({click:function(){$('#'+elementId).fadeOut('slow');}});
      }
    });
  }
  else
  {
    return false;
  }
}