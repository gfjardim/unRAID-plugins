var PreclearURL = '/plugins/'+plugin+'/Preclear.php'

if (! $.tooltipster)
{
  $("<link rel='stylesheet' type='text/css' href='/plugins/"+plugin+"/assets/tooltipster.bundle.hibrid.css'>").appendTo("head");
  $("<script type='text/javascript' src='/plugins/"+plugin+"/assets/tooltipster.bundle.min.js'>").appendTo("head");
}

String.prototype.formatUnicorn = String.prototype.formatUnicorn ||
function () {
    "use strict";
    var str = this.toString();
    if (arguments.length) {
        var t = typeof arguments[0];
        var key;
        var args = ("string" === t || "number" === t) ?
            Array.prototype.slice.call(arguments)
            : arguments[0];

        for (key in args) {
            str = str.replace(new RegExp("\\{" + key + "\\}", "gi"), args[key]);
        }
    }

    return str;
};

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
    zIndex:999,
    trigger:'custom',
    triggerOpen:{mouseenter:true, touchstart:true},
    triggerClose:onClose,
  }).tooltipster('open');
});


function getPreclearContent()
{
  clearTimeout(timers.preclear);
  $.post(PreclearURL,{action:'get_content',display:display},function(data)
  {
    var hovered = $( ".tooltip:hover" ).map(function(){return this.id;}).get();
    if ( $('#preclear-table-body').length )
    {
      var target = $( '#preclear-table-body' );
      currentScroll  = $(window).scrollTop();
      currentToggled = getToggledReports();
      target.html( data.disks );
      toggleReports(currentToggled);
      $(window).scrollTop(currentScroll);
    }

    $.each(data.status, function(i,v)
    {
      var target = $("#preclear_"+i);
      var icon = "#preclear_footer_" + i;
      
      $("#preclear_"+i).html("<i class='fa fa-tachometer hdd'></i><span style='margin-left: 0px;'></span>"+v.status);

      if (! $(icon).length)
      {
        el  = "<a class='exec' title='' id='"+icon.substring(1)+"'><img src='/plugins/"+plugin+"/icons/precleardisk.png'></a>";
        el  = $(el).prependTo("#preclear-footer").css("margin-right", "6px");
        el.tooltipster(
        {
          delay:100,
          zIndex:100,
          trigger:'custom',
          triggerOpen:{mouseenter:true, touchstart:true},
          triggerClose:{click:false, scroll:true, mouseleave:true, tap:true},
          contentAsHTML: true,
          interactive: true,
          updateAnimation: false,
          functionBefore: function(instance, helper)
          {
            instance.content($(helper.origin).attr("data"));
          }
        });
      }
      content = $("<div>").append(v.footer);
      content.find("a[id^='preclear_rm_']").attr("id", "preclear_footer_rm_" + i);
      content.find("a[id^='preclear_open_']").attr("id", "preclear_footer_open_" + i);
      $(icon).tooltipster('content', content.html());
    });

    $.each(hovered, function(k,v){ if(v.length) { $("#"+v).trigger("mouseenter");} });

    window.disksInfo = JSON.parse(data.info);

    if (typeof(startDisk) !== 'undefined')
    {
      startPreclear(startDisk);
      delete window.startDisk;
    }
  },'json').always(function(data)
  {
    timers.preclear = setTimeout('getPreclearContent()', ($(data.status).length > 0) ? 5000 : 15000);
  }).fail(updateCsrfToken);
}

function updateCsrfToken(jqXHR, textStatus, error)
{
  if (jqXHR.status == 200)
  {
    console.log("Updating CSRF token");
    $.get(PreclearURL,{action: "get_csrf_token"}, function(response)
    {
      $.ajaxPrefilter(function(s, orig, xhr){
        if (s.type.toLowerCase() == "post" && ! s.crossDomain) {
          s.data = s.data.replace(/csrf_token=.{16}/g, "csrf_token=" + response.csrf_token);
        }
      }); 
    }, "json").fail(function(s,o,xhr){location.reload(true);});
  }
}

function openPreclear(serial)
{
  var width   = 1000;
  var height  = 730;
  var top     = (screen.height-height)/2;
  var left    = (screen.width-width)/2;
  var options = 'resizeable=yes,scrollbars=yes,height='+height+',width='+width+',top='+top+',left='+left;
  window.open('/plugins/'+plugin+'/Preclear.php?action=show_preclear&serial='+serial, 'Preclear', options);
}


function toggleScript(el, serial)
{
  window.scope = $(el).val();
 
  startPreclear( serial );
}


function startPreclear(serial)
{
  if (typeof(serial) === 'undefined')
  {
    return false;
  }

  preclear_dialog = $( "#preclear-dialog" );

  var opts = {
    family:       getDiskInfo(serial, 'FAMILY'),
    model:        getDiskInfo(serial, 'MODEL'),
    serial_short: getDiskInfo(serial, 'SERIAL_SHORT'),
    firmware:     getDiskInfo(serial, 'FIRMWARE'),
    size_h:       getDiskInfo(serial, 'SIZE_H')
    };

  var header = $("#dialog-header-defaults").html();

  preclear_dialog.html( header.formatUnicorn(opts) );
  preclear_dialog.append("<hr style='margin-left:12px;'>");

  if (typeof(scripts) !== 'undefined')
  {
    size = Object.keys(scripts).length;

    if (size)
    {
      var options = "<dl class='dl-dialog'><dt>Script<st><dd><select onchange='toggleScript(this,\""+serial+"\");'>";
      $.each( scripts, function( key, value )
        {
          var sel = ( key == scope ) ? "selected" : "";
          options += "<option value='"+key+"' "+sel+">"+authors[key]+"</option>";
        }
      );
      preclear_dialog.append(options+"</select></dd></dl>");

    }
  }

  preclear_dialog.append($("#"+scope+"-start-defaults").html());

  swal(
  {
    title: "Start Preclear",
    text:  preclear_dialog.html(),
    type:  "info",
    html:  true,
    closeOnConfirm: false,
    showCancelButton: true,
    confirmButtonText:"Start",
    cancelButtonText:"Cancel"
  }, function(result)
  {
    if (result)
    {
       // $('button:eq(0)',$('#dialog_id').dialog.buttons).button('disable');
      // $(e.target).attr('disabled', true);
      var opts       = new Object();
      popup = $(".sweet-alert.showSweetAlert > p:first");
      opts["action"] = "start_preclear";
      opts["device"] = getDiskInfo(serial, 'DEVICE');
      opts["op"]     = getVal(popup, "op");
      opts["scope"]  = scope;

      if (scope == "joel")
      {
        opts["-c"]  = getVal(popup, "-c");
        opts["-o"]  = getVal(popup, "preclear_notify1") == "on" ? 1 : 0;
        opts["-o"] += getVal(popup, "preclear_notify2") == "on" ? 2 : 0;
        opts["-o"] += getVal(popup, "preclear_notify3") == "on" ? 4 : 0;
        opts["-M"]  = getVal(popup, "-M");
        opts["-r"]  = getVal(popup, "-r");
        opts["-w"]  = getVal(popup, "-w");
        opts["-W"]  = getVal(popup, "-W");
        opts["-f"]  = getVal(popup, "-f");
        opts["-s"]  = getVal(popup, "-s");
      }

      else
      {
        opts["--cycles"]        = getVal(popup, "--cycles");
        opts["--notify"]        = getVal(popup, "preclear_notify1") == "on" ? 1 : 0;
        opts["--notify"]       += getVal(popup, "preclear_notify2") == "on" ? 2 : 0;
        opts["--notify"]       += getVal(popup, "preclear_notify3") == "on" ? 4 : 0;
        opts["--frequency"]     = getVal(popup, "--frequency");
        opts["--skip-preread"]  = getVal(popup, "--skip-preread");
        opts["--skip-postread"] = getVal(popup, "--skip-postread");      
        opts["--test"]          = getVal(popup, "--test");      
      }

      $.post(PreclearURL, opts, function(data)
              {
                openPreclear(serial);
              }
            ).always(function(data)
              {
                window.location=window.location.pathname+window.location.hash;
              }
            ).fail(updateCsrfToken);

      swal.close();

    }
    else
    {
      swal.close();
    }
  });
}


function stopPreclear(serial, ask)
{
  var title = 'Stop Preclear';
  var exec  = '$.post(PreclearURL,{action:"stop_preclear",serial:"'+serial+'"}).always(function(){window.location=window.location.pathname+window.location.hash}).fail(updateCsrfToken);'

  if (ask != "ask")
  {
    eval(exec);
    ;
    return true;
  }

  preclear_dialog = $( "#preclear-dialog" );

  var opts = {
    family:       getDiskInfo(serial, 'FAMILY'),
    model:        getDiskInfo(serial, 'MODEL'),
    serial_short: getDiskInfo(serial, 'SERIAL_SHORT'),
    firmware:     getDiskInfo(serial, 'FIRMWARE'),
    size_h:       getDiskInfo(serial, 'SIZE_H')
    };

  var header = $("#dialog-header-defaults").html();

  preclear_dialog.html("<div>" + header.formatUnicorn(opts) + "");

  swal(
  {
    title: "Stop Preclear",
    text:  preclear_dialog.html(),
    type:  "warning",
    html:  true,
    closeOnConfirm: false,
    showCancelButton: true,
    confirmButtonText:"Stop",
    cancelButtonText:"Cancel"
  }, function(result)
  {
    if (result)
    {
      eval(exec);
    }
    swal.close();
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
      if ( $("div.toggle-"+disk+":first").is(":visible") )
      {
        elem.find(".fa-append").removeClass("fa-minus-circle").addClass("fa-plus-circle");
      }
      else
      {
        elem.find(".fa-append").addClass("fa-minus-circle").removeClass("fa-plus-circle");
      }
      $(".toggle-"+disk).slideToggle(150);
    });

    if (typeof(opened) !== 'undefined')
    {
      if ( $.inArray(disk, opened) > -1 )
      {
        $(".toggle-"+disk).css("display","block");
        elem.find(".fa-append").addClass("fa-minus-circle").removeClass("fa-plus-circle");
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
        $(el).closest("td").find(".fa-minus-circle, .fa-plus-circle").css("opacity", "0.0");
      }
      $(el).parent().remove();
    }
  }).fail(updateCsrfToken);
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
  
  if (element.find("input[type='button']").length )
  {
    element.addClass("vhshift");
    element.find("input[type='button']").prop("style","padding-top: 5px; padding-bottom: 5px; margin-top:-3px; margin-bottom:0;");
  }

  if (Target.prop('nodeName') === "DIV")
  {
    element.addClass("vhshift");
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

function getResumablePreclear(serial)
{
  $.post(PreclearURL,{action:'get_resumable', serial:serial}, function(data)
  {
    if (data.resume)
    {
      swal(
      {
        title: "Resume Preclear?",
        text:  "There's a previous preclear session available for this drive.<br>Do you want to resume it instead of starting a new one?",
        type:  "info",
        html:  true,
        closeOnConfirm: false,
        showCancelButton: true,
        confirmButtonText:"Resume",
        cancelButtonText:"Cancel"
      }, function(result)
      {
        if (result)
        {
          swal.close();

          var opts       = new Object();
          opts["action"] = "start_preclear";
          opts["serial"] = serial;
          opts["device"] = getDiskInfo(serial, 'DEVICE');
          opts["op"]     = "resume";
          opts["scope"]  = "gfjardim";

          $.post(PreclearURL, opts, function(data)
                  {
                    openPreclear(serial);
                  }
                ).always(function(data)
                  {
                    window.location=window.location.pathname+window.location.hash;
                  }
                );
        }
        else
        {
          swal.close();
          setTimeout(startPreclear, 300, serial);
        }
      }).fail(updateCsrfToken);
    }
    else
    {
      startPreclear(serial);
    }
  }, "json");
}

function resumePreclear(disk)
{
  $.post(PreclearURL,{action:'resume_preclear', disk:disk}, function(data)
  {
    getPreclearContent();
  }).fail(updateCsrfToken);
}