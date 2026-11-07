/*
  Global letiable
*/
Ext.Ajax.on('beforerequest', function(conn, options) {
    if (!options.url) {
        return;
    }

    var pathname = window.location.pathname;

    // Deteksi root aplikasi: /rab-project/dev/ atau /rab-project/live/
    var match = pathname.match(
        /^(.*?\/rab-project\/(?:dev|live)\/)/i
    );

    if (!match) {
        console.error('APP ROOT tidak ditemukan:', pathname);
        return;
    }

    var appRoot = match[1];

    // URL eksternal tidak boleh diubah.
    if (/^(https?:)?\/\//i.test(options.url)) {
        return;
    }

    var url = options.url;

    // URL absolut yang sudah menunjuk ke aplikasi dev/live dipertahankan.
    if (/^\/rab-project\/(?:dev|live)\//i.test(url)) {
        return;
    }

    // URL absolut lama /rab-project/... diarahkan ke root aktif.
    if (/^\/rab-project\//i.test(url)) {
        options.url = url.replace(
            /^\/rab-project\//i,
            appRoot
        );
        return;
    }

    // Hapus prefix relatif ../ berulang.
    url = url.replace(/^(\.\.\/)+/, '');

    // Hilangkan prefix dev/ atau live/ jika sudah ada.
    url = url.replace(/^(?:dev|live)\//i, '');

    // URL dimulai dari root aplikasi aktif.
    options.url = appRoot + url.replace(/^\/+/, '');
});

Ext.ns("Ext.ux.renderer");
let elStyle=0, SearchData='', PageSize=250, EmptyArr=[], task='', dataRecord=[], historyRecord=[], deleteRow=0, deleteGridData=0, deleteRowIndex=0, deleteRecord=null;
let cssGridEditColumn = 'background-color:rgba(166, 157, 82, .1); color: #228bcd;';
let gridRibbonCell = 'padding: 8px 10px !important;';
let cssGridYellow = 'color: #fdaf3d;';
let cssGridRed = 'color: #db3955;';
let main_url = '../' + (window.location.pathname.includes('/live/') ? 'live' : 'dev');

let historyField = [
  {name: 'id', type : 'int'},
  {name: 'date', type:'date', dateFormat: 'Y-m-d'},
  {name: 'count', type : 'float'},
  {name: 'amend_name', type : 'string'},
  {name: 'before_amendment', type : 'string'},
  {name: 'after_amendment', type : 'string'},
  {name: 'user_name', type : 'string'},
  {name: 'user_time'}
];
let machineField =[
  {name: 'machine_id',type:'int'},
  {name: 'machine_name',type:'string'},
  {name: 'machine_no',type:'string'},
];
let onLeaveField =[
  {name: 'leave_id',type:'int',convert : function(v, rec) {return rec.id;}},
  {name: 'leave_name',type:'string',convert : function(v, rec) {return rec.name;}},
  {name: 'leave_init',type:'string',convert : function(v, rec) {return rec.init;}},
];
let supplierField =[
  {name: 'supplier_id',type:'int',convert : function(v, rec) {return rec.id;}},
  {name: 'supplier_name',type:'string',convert : function(v, rec) {return rec.name;}},
  {name: 'supplier_init',type:'string',convert : function(v, rec) {return rec.init;}},
];
let supplierLogField =[
  {name: 'supplier_id',type:'int'},
  {name: 'supplier_name',type:'string'}
];
let hphField =[
  {name: 'hph_id'},
  {name: 'hph_name',type:'string',convert : function(v, rec) {return rec.hph;}}
];
let customerField =[
  {name: 'customer_id',type:'int',convert : function(v, rec) {return rec.id;}},
  {name: 'customer_name',type:'string',convert : function(v, rec) {return rec.name;}}
];
let agentField =[
  {name: 'agent_id',type:'int',convert : function(v, rec) {return rec.id;}},
  {name: 'agent_name',type:'string',convert : function(v, rec) {return rec.name;}}
];
let salesField =[
  {name : 'id', type:'int'},
  {name : 'sales_id', type:'int'},
  {name : 'sales_name',type:'string',convert : function(v, rec) {return rec.employee_name;}},
  {name : 'group_name'},
  {name : 'email'}
];
let salesGroupField =[
  {name: 'sls_group_id',type:'int',convert : function(v, rec) {return rec.id;}},
  {name: 'sls_group_init',type:'string',convert : function(v, rec) {return rec.init;}},
  {name: 'sls_group_name',type:'string',convert : function(v, rec) {return rec.name;}}
];
let colorField =[
  {name: 'color_id',type:'int',convert : function(v, rec) {return rec.id;}},
  {name: 'color_init',type:'string',convert : function(v, rec) {return rec.init;}},
  {name: 'color_name',type:'string',convert : function(v, rec) {return rec.name;}}
];
let umField =[
  {name: 'um_id',type:'int',convert : function(v, rec) {return rec.id;}},
  {name: 'um_init',type:'string',convert : function(v, rec) {return rec.init;}},
  {name: 'um_name',type:'string',convert : function(v, rec) {return rec.name;}}
];
let currencyField =[
  {name: 'currency_id',type:'int',convert : function(v, rec) {return rec.id;}},
  {name: 'currency_init',type:'string',convert : function(v, rec) {return rec.init;}},
  {name: 'currency_name',type:'string',convert : function(v, rec) {return rec.name;}}
];
let shipTermsField =[
  {name: 'shipterms_id',type:'int',convert : function(v, rec) {return rec.id;}},
  {name: 'shipterms_init',type:'string',convert : function(v, rec) {return rec.init;}},
  {name: 'shipterms_name',type:'string',convert : function(v, rec) {return rec.name;}}
];
let payTermsField =[
  {name: 'payterms_id',type:'int',convert : function(v, rec) {return rec.id;}},
  {name: 'payterms_init',type:'string',convert : function(v, rec) {return rec.init;}},
  {name: 'payterms_name',type:'string',convert : function(v, rec) {return rec.name;}}
];
let incoTermsField =[
  {name: 'incoterms_id',type:'int',convert : function(v, rec) {return rec.id;}},
  {name: 'incoterms_init',type:'string',convert : function(v, rec) {return rec.init;}},
  {name: 'incoterms_name',type:'string',convert : function(v, rec) {return rec.name;}}
];
let itemGradeField =[
  {name: 'grade_id',type:'int',convert : function(v, rec) {return rec.id;}},
  {name: 'grade_init',type:'string',convert : function(v, rec) {return rec.init;}},
  {name: 'grade_name',type:'string',convert : function(v, rec) {return rec.name;}}
];
let groupGradeField =[
  {name: 'grade_id',type:'int'},
  {name: 'grade_name',type:'string'}
];
let woodTypeField =[
  {name: 'wood_id',type:'int',convert : function(v, rec) {return rec.id;}},
  {name: 'wood_name',type:'string',convert : function(v, rec) {return rec.name;}}
];
let locationField =[
  {name: 'location_id',type:'int',convert : function(v, rec) {return rec.id;}},
  {name: 'location_init',type:'string',convert : function(v, rec) {return rec.init;}},
  {name: 'location_name',type:'string',convert : function(v, rec) {return rec.name;}}
];
let departmentField =[
  {name: 'department_id',type:'int',convert : function(v, rec) {return rec.id;}},
  {name: 'department_init',type:'string',convert : function(v, rec) {return rec.init;}},
  {name: 'department_name',type:'string',convert : function(v, rec) {return rec.name;}}
];
let itemTypeField =[
  {name: 'item_type_id',type:'int',convert : function(v, rec) {return rec.id;}},
  {name: 'item_type_init',type:'string',convert : function(v, rec) {return rec.init;}},
  {name: 'item_type_name',type:'string',convert : function(v, rec) {return rec.name;}}
];
let warehouseField =[
  {name: 'warehouse_id',type:'int',convert : function(v, rec) {return rec.id;}},
  {name: 'warehouse_init',type:'string',convert : function(v, rec) {return rec.init;}},
  {name: 'warehouse_name',type:'string',convert : function(v, rec) {return rec.location_new.concat(' ~ ',rec.warehouse_name);}}
];
let txTypeField =[
  {name : 'tx_type_id',type:'int',convert : function(v, rec) {return rec.id;}},
  {name : 'tx_type_init',type:'string',convert : function(v, rec) {return rec.init;}},
  {name : 'tx_type_name',type:'string',convert : function(v, rec) {return rec.name;}},
  {name : 'tx_type_group',type:'string',convert : function(v, rec) {return rec.group_type;}}
];
let readEmployeeField = [
  {name : 'employee_id',type:'int'},
  {name : 'nik',type:'int'},
  {name : 'employee_name',type:'string'},
  {name : 'location_name',type:'string'},
  {name : 'department_name',type:'string'},
  {name : 'section_name',type:'string'}
];

let dayName = new Array(7);
dayName[0] = 'Sunday';
dayName[1] = 'Monday';
dayName[2] = 'Tuesday';
dayName[3] = 'Wednesday';
dayName[4] = 'Thursday';
dayName[5] = 'Friday';
dayName[6] = 'Saturday';

let monthName = new Array(12);
monthName[0] = 'January';
monthName[1] = 'February';
monthName[2] = 'March';
monthName[3] = 'April';
monthName[4] = 'May';
monthName[5] = 'June';
monthName[6] = 'July';
monthName[7] = 'August';
monthName[8] = 'September';
monthName[9] = 'October';
monthName[10] = 'November';
monthName[11] = 'December';

/* Style for extjs field */
let FieldSpace={xtype:'label', text:'-', cls:'x-form-item my-label-styleW'};
let RowSpace={xtype:'label', text:'-', cls:'x-form-item my-label-styleRow'};
let ButtonSpace={xtype:'label', text:'-', cls:'x-form-item my-label-styleButton'};
let FieldSpaceTopLabel={xtype:'label', text:'-', cls:'x-form-item my-label-spaceTopLabel'};
let spaceTextArea={xtype:'label', text:'-', cls:'x-form-item my-label-spaceTextArea'};
let spaceTextField={xtype:'label', text:'-', cls:'x-form-item my-label-spaceTextField'};

/*
  main function
*/

Date.prototype.addDays = function (num) {
    let value = this.valueOf();
    value += 86400000 * num;
    return new Date(value);
}

Date.prototype.addSeconds = function (num) {
    let value = this.valueOf();
    value += 1000 * num;
    return new Date(value);
}

Date.prototype.addMinutes = function (num) {
    let value = this.valueOf();
    value += 60000 * num;
    return new Date(value);
}

function $N(value, ifnull) {
    if (value === null || value === undefined)
      return ifnull;
    return value;
 }
function roundNumber(value, decimals) {
  return Number(Math.round(value+'e'+decimals)+'e-'+decimals);
}
Ext.form.Field.prototype.msgTarget = 'side';
function saveEditEnable(){
  document.getElementById("saveEdit").style.pointerEvents = 'auto';
  document.getElementById("saveEdit").style.opacity = '1';
  //document.getElementById("saveEdit").style.backgroundColor = '#1D6788';
  //document.getElementById("saveEdit").style.color = '#fff';
}
function saveEditDisable(){
  document.getElementById("saveEdit").style.pointerEvents = 'none';
  document.getElementById("saveEdit").style.opacity = '.2';
  //document.getElementById("saveEdit").style.backgroundColor = '#77A3B7';
  //document.getElementById("saveEdit").style.color = '#B3B3B3';
}

function addSaveEditEnable(){
  document.getElementById("addSaveEdit").style.pointerEvents = 'auto';
  document.getElementById("addSaveEdit").style.backgroundColor = '#1D6788';
  document.getElementById("addSaveEdit").style.color = '#fff';
}
function addSaveEditDisable(){
  document.getElementById("addSaveEdit").style.pointerEvents = 'none';
  document.getElementById("addSaveEdit").style.backgroundColor = '#77A3B7';
  document.getElementById("addSaveEdit").style.color = '#B3B3B3';
}

function editDataEnable(){
  $("#editData").css({'pointer-events':'auto'});
  $("#editData t, #editData i").css({'opacity':'1'});
}
function editDataDisable(){
  $("#editData").css({'pointer-events':'none'});
  $("#editData t, #editData i").css({'opacity':'.5'});
}

function mainBtnEnable(divId){
  let divBtn=divId, divBtnText=divId+' t', divBtnIcon=divId+' i';
  $(divBtn).css({'pointer-events':'auto'});
  $(divBtnText).css({'opacity':'1'});
  $(divBtnIcon).css({'opacity':'1'});
}
function mainBtnDisable(divId){
  let divBtn=divId, divBtnText=divId+' t', divBtnIcon=divId+' i';
  $(divBtn).css({'pointer-events':'none'});
  $(divBtnText).css({'opacity':'.4'});
  $(divBtnIcon).css({'opacity':'.4'});
}

function popWdwClose(){
  document.getElementById('popWdw').style.display='none';
  document.getElementById('popCancel').style.display='none';
}
function logWdwClose(){document.getElementById('logWdw').style.display='none';}
function editWdwClose(){
  if (document.getElementById("mainHeaderText").innerText=='Change Password ') {
    window.close();
  } else {
    document.getElementById('editorWdw').style.display='none';
  }
}
function addWdwClose(){document.getElementById('addWdw').style.display='none';}
function uploadWdwClose(){document.getElementById('uploadWdw').style.display='none';}
function addSecondWdwClose(){document.getElementById('addSecondWdw').style.display='none';}
function prnWdwClose(){document.getElementById('prnWdw').style.display='none';}
function chartWdwClose(){document.getElementById('chartWdw').style.display='none';}

function comboDisable(id){
  Ext.getCmp(id).getEl().dom.setAttribute('readOnly', true);
  Ext.getCmp(id).readOnly = true;
}
function comboEnable(id){
  Ext.getCmp(id).getEl().dom.removeAttribute('readOnly');
  Ext.getCmp(id).readOnly = false;
}
function systemLinksClick(){
  if (elStyle===0) {showLinksMenu(); elStyle=1;}
  else {hideLinksMenu(); elStyle=0;}
}
function systemLinksClose(){hideLinksMenu(); elStyle=0;}
function readPhotoURL(input) {
  if (input.files && input.files[0]) {
    let reader = new FileReader();
    reader.onload = function (e) {document.getElementById('dataPicPrev').src=e.target.result;};
    reader.readAsDataURL(input.files[0]);
  }
}
function showLinksMenu(){
  document.getElementById('content').style.transform = 'translateX(-30%)';
  document.getElementById('slide-menu').style.transform = 'translateX(0px)';
  document.getElementById('slide-menu').style.opocity='1';
  document.getElementById('slide-menu').style.display='block';
  document.getElementById('slide-menu').style['border-left'] = 'solid 1px #000';
  document.getElementById('linksOverlay').style.display='block';
}
function hideLinksMenu(){
  document.getElementById('content').style.transform = 'translateX(0px)';
  document.getElementById('slide-menu').style.transform = 'translateX(30%)';
  document.getElementById('slide-menu').style.opocity='0';
  document.getElementById('slide-menu').style.display='none';
  document.getElementById('slide-menu').style['border-left'] = 'solid 1px #111';
  document.getElementById('linksOverlay').style.display='none';
}

function setSession(letiable, value) {
  xmlhttp = new XMLHttpRequest();
  xmlhttp.open("GET", "../php/setSession.php?letiable=" + letiable + "&value=" + value, true);
  xmlhttp.send();
}
function textEnable(elementId,c) {
  colorCode=(c===null) ? '#FFF !important' : c;
  document.getElementById(elementId).style.pointerEvents = 'auto';
  document.getElementById(elementId).style.textDecoration = 'none';
  document.getElementById(elementId).style.color = colorCode;
  document.getElementById(elementId).style.textTransform = 'capitalize';
}
function textDisable(elementId,c) {
  colorCode=(c===null) ? '#adadad !important' : c;
  document.getElementById(elementId).style.pointerEvents = 'none';
  document.getElementById(elementId).style.color = colorCode;
  document.getElementById(elementId).style.textTransform = 'lowercase';
}
/* Calculate the height for grid to fit on screen*/
function getPosition(element) {
  let xPosition = 0;
  let yPosition = 0;
  while(element) {
      xPosition += (element.offsetLeft - element.scrollLeft + element.clientLeft);
      yPosition += (element.offsetTop - element.scrollTop + element.clientTop);
      element = element.offsetParent;
  }
  return { x: xPosition, y: yPosition };
}
function maxGridHeight(element){
  let position = getPosition(element);
  let GridHeight=(window.innerHeight-position.y);
  return Height=Math.round(GridHeight);
}
function replaceEnterKeyWithTab(){if(event.keyCode == 13){event.keyCode=9;}}
function calculateDivHeight(dataDiv){
  let mainDivHeight=document.getElementById(dataDiv[0]).clientHeight;
  for (h=1;h<dataDiv.length;h++){
    mainDivHeight -= document.getElementById(dataDiv[h]).clientHeight;
  }
  return mainDivHeight;
}
/* Radio button function */
function unhideRadio(elementId){
  document.getElementById(elementId).style.pointerEvents = 'auto';
  document.getElementById(elementId).classList.remove('disableClass');
  let labels = document.getElementsByClassName('x-form-item-label');
  for (let i = 0; i < labels.length; i++) {
    if (labels[i].htmlFor==elementId) {document.getElementsByClassName('x-form-item-label')[i].style.color = "#a69e9e";}
  }
}
function hideRadio(elementId){
  document.getElementById(elementId).style.pointerEvents = 'none';
  document.getElementById(elementId).className += " disableClass";
  let labels = document.getElementsByClassName('x-form-item-label');
  for (let i = 0; i < labels.length; i++) {
    if (labels[i].htmlFor==elementId) {document.getElementsByClassName('x-form-item-label')[i].style.color = "#ededed";}
  }
}
function getRadioGroupValue(radioId){
  for (y=0;y<radioId.items.items.length;y++){
    if (radioId.items.items[y].getValue()===true) {
      return radioId.items.items[y].inputValue;
      break;
    }
  }
}
function gridIconHeader(color,size,icn){
  return '<div style="text-align:center; color:'+color+';"> <i class="material-icons md-'+size+'">'+icn+'</i></div>';
}
/*************************************************/
/* Grid Combo renderer */
/*************************************************/
Ext.util.Format.comboRenderer = function(comboGrid){
  return function(value){
    let recordCombo = comboGrid.findRecord(comboGrid.valueField, value);
    return recordCombo ? '<div style="white-space:normal !important;">'+ recordCombo.get(comboGrid.displayField) +'</div>': comboGrid.valueNotFoundText;
  };
};

Ext.ux.renderer.ComboRenderer = function(options) {
    let value = options.value;
    let combo = options.combo;
    let returnValue = value;
    let valueField = combo.valueField;
    let idx = combo.store.findBy(function(record) {if(record.get(valueField) == value) {returnValue = record.get(combo.displayField); return true;}});
    if(idx < 0 && value == 0) {returnValue = '';}
    return returnValue;
};
Ext.ux.renderer.Combo = function(combo) {
  return function(value, meta, record) {
    return Ext.ux.renderer.ComboRenderer({value: value, meta: meta, record: record, combo: combo});
  };
}

function logStatus(val, x, store){
  switch (store.data.status_id) {
    case 1:
    return 'Log End';
    case 2:
    return 'Log Core';
    case 3:
    return 'Log Asal';
    case 4:
    return 'Rotary';
    case 5:
    return 'Waste';
    default:
    return '-';
  }
}
/*************************************************/
function strip_tags(input, allowed) {
  allowed = (((allowed || '') + '')
  .toLowerCase()
  .match(/<[a-z][a-z0-9]*>/g) || [])
  .join('');
  let tags = /<\/?([a-z][a-z0-9]*)\b[^>]*>/gi,commentsAndPhpTags = /<!--[\s\S]*?-->|<\?(?:php)?[\s\S]*?\?>/gi;
  return input.replace(commentsAndPhpTags, '')
  .replace(tags, function($0, $1) {return allowed.indexOf('<' + $1.toLowerCase() + '>') > -1 ? $0 : '';});
}
function $N(value, ifnull) {if (value === null || value === undefined || value=='') return ifnull; return value;}
function format_number(value){if ($N(value,0)==0) {return '<b>-</b>';} else {return Ext.util.Format.number(value,'?0,0.00?');}};
function format_seq(value){if ($N(value,0)==0) {return '<b>-</b>';} else {return Ext.util.Format.number(value,'?00?');}};
function format_int(value){if ($N(value,0)==0) {return '<b>-</b>';} else {return Ext.util.Format.number(value,'?0,0?');}};
function format_curr_idr(value){if ($N(value,0)==0) {return '<b>-</b>';} else {return Ext.util.Format.number(value,'?0,0?');}};
function format_value(value){if ($N(value,0)==0) {return '<b>-</b>';} else {return Ext.util.Format.number(value,'?0,0.0000?');}};

function DetailHeight(PercentHeight){return ((window.innerHeight*PercentHeight)/100);}
function CellWrap(value){
  if (value === null || value === undefined || value==='' || value==='0') return '<div style="white-space:normal !important;">-</div>';
  return '<div style="white-space:normal !important;">'+ value +'</div>';
}
function rendererLogVolume(value, store) {
  return roundNumber(((.7854*store.data.length*(roundNumber(((store.data.dia_1+store.data.dia_2+store.data.dia_3+store.data.dia_4)/4),0))*(roundNumber(((store.data.dia_1+store.data.dia_2+store.data.dia_3+store.data.dia_4)/4),0)))/1000000),4);
}
function rendererDiaAvrg(value, store) {
  return roundNumber(((store.data.dia_1+store.data.dia_2+store.data.dia_3+store.data.dia_4)/4),0);
}

/*
 * Date Format 1.2.3
 * (c) 2007-2009 Steven Levithan <stevenlevithan.com>
 * MIT license
 *
 * Includes enhancements by Scott Trenda <scott.trenda.net>
 * and Kris Kowal <cixar.com/~kris.kowal/>
 *
 * Accepts a date, a mask, or a date and a mask.
 * Returns a formatted version of the given date.
 * The date defaults to the current date/time.
 * The mask defaults to dateFormat.masks.default.
*/
let dateFormat = function () {
  let token = /d{1,4}|m{1,4}|yy(?:yy)?|([HhMsTt])\1?|[LloSZ]|"[^"]*"|'[^']*'/g,
    timezone = /\b(?:[PMCEA][SDP]T|(?:Pacific|Mountain|Central|Eastern|Atlantic) (?:Standard|Daylight|Prevailing) Time|(?:GMT|UTC)(?:[-+]\d{4})?)\b/g,
    timezoneClip = /[^-+\dA-Z]/g,
    pad = function (val, len) {
      val = String(val);
      len = len || 2;
      while (val.length < len) val = "0" + val;
      return val;
    };

  // Regexes and supporting functions are cached through closure
  return function (date, mask, utc) {
    let dF = dateFormat;
    // You can't provide utc if you skip other args (use the "UTC:" mask prefix)
    if (arguments.length == 1 && Object.prototype.toString.call(date) == "[object String]" && !/\d/.test(date)) {
      mask = date;
      date = undefined;
    }
    // Passing date through Date applies Date.parse, if necessary
    date = date ? new Date(date) : new Date;
    if (isNaN(date)) throw SyntaxError("proyekalid date");

    mask = String(dF.masks[mask] || mask || dF.masks["default"]);

    // Allow setting the utc argument via the mask
    if (mask.slice(0, 4) == "UTC:") {
      mask = mask.slice(4);
      utc = true;
    }

    let _ = utc ? "getUTC" : "get",
      d = date[_ + "Date"](),
      D = date[_ + "Day"](),
      m = date[_ + "Month"](),
      y = date[_ + "FullYear"](),
      H = date[_ + "Hours"](),
      M = date[_ + "Minutes"](),
      s = date[_ + "Seconds"](),
      L = date[_ + "Milliseconds"](),
      o = utc ? 0 : date.getTimezoneOffset(),
      flags = {
        d:    d,
        dd:   pad(d),
        ddd:  dF.i18n.dayNames[D],
        dddd: dF.i18n.dayNames[D + 7],
        m:    m + 1,
        mm:   pad(m + 1),
        mmm:  dF.i18n.monthNames[m],
        mmmm: dF.i18n.monthNames[m + 12],
        yy:   String(y).slice(2),
        yyyy: y,
        h:    H % 12 || 12,
        hh:   pad(H % 12 || 12),
        H:    H,
        HH:   pad(H),
        M:    M,
        MM:   pad(M),
        s:    s,
        ss:   pad(s),
        l:    pad(L, 3),
        L:    pad(L > 99 ? Math.round(L / 10) : L),
        t:    H < 12 ? "a"  : "p",
        tt:   H < 12 ? "am" : "pm",
        T:    H < 12 ? "A"  : "P",
        TT:   H < 12 ? "AM" : "PM",
        Z:    utc ? "UTC" : (String(date).match(timezone) || [""]).pop().replace(timezoneClip, ""),
        o:    (o > 0 ? "-" : "+") + pad(Math.floor(Math.abs(o) / 60) * 100 + Math.abs(o) % 60, 4),
        S:    ["th", "st", "nd", "rd"][d % 10 > 3 ? 0 : (d % 100 - d % 10 != 10) * d % 10]
      };

    return mask.replace(token, function ($0) {
      return $0 in flags ? flags[$0] : $0.slice(1, $0.length - 1);
    });
  };
}();
// Some common format strings
dateFormat.masks = {
  "default"     :      "ddd mmm dd yyyy HH:MM:ss",
  shortDate     :      "m/d/yy",
  mediumDate    :     "mmm d, yyyy",
  longDate      :       "mmmm d, yyyy",
  fullDate      :       "dddd, mmmm d, yyyy",
  shortTime     :      "h:MM TT",
  mediumTime    :     "h:MM:ss TT",
  longTime      :       "h:MM:ss TT Z",
  isoDate       :        "yyyy-mm-dd",
  isoTime       :        "HH:MM:ss",
  isoDateTime   :    "yyyy-mm-dd'T'HH:MM:ss",
  isoUtcDateTime: "UTC:yyyy-mm-dd'T'HH:MM:ss'Z'"
};
// Internationalization strings
dateFormat.i18n = {
  dayNames: ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat","Sunday", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"],
  monthNames: ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec","January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"]
};
// For convenience...
Date.prototype.format = function (mask, utc) {return dateFormat(this, mask, utc);};

function isDate(val) {
    if (val === null || val === undefined || val === '') {
        return false;
    }
    if (Ext.isDate(val) || Object.prototype.toString.call(val) === '[object Date]') {
        return !isNaN(val.getTime());
    }
    if (typeof val === 'string' || typeof val === 'number') {
        let d = new Date(val);
        return !isNaN(d.getTime());
    }
    return false;
}

function diff2date(date1,date2){
  let difference = 0;
  let diffDays = 0;
  difference = date1 - date2;
  diffDays = Math.round(difference/(1000*60*60*24));
  return diffDays;
}

function Grid2Json(GridName){
  let i=0;
  let recordData=[];
  let jsonRecord = [];
  for (i=0;i<GridName.store.getCount();i++){
    recordData=GridName.store.getAt(i);
    jsonRecord.push(recordData.data);
  };
  return Ext.encode(jsonRecord);
};

function DsArray2Json(DsName){
  let i=0;
  let recordData=[];
  let jsonRecord = [];
  for (i=0;i<DsName.data.length;i++){
    recordData=DsName.data.items[i];
    jsonRecord.push(recordData.data);
  }
  return Ext.encode(jsonRecord);
}

function Array2Json(arrayName){return Ext.encode(arrayName);}

function dataStore2Array(DsName){
  let i=0;
  let recordData=[];
  let arrayRecord = [];
  for (i=0;i<DsName.data.length;i++){
    recordData=DsName.data.items[i];
    arrayRecord.push(recordData.data);
  }
  return arrayRecord;
}

function setGridEditorCellDisable(gridEditor, collText){
  for (i=0;i<collText.length;i++){
    dataCol=collText[i];
    //gridEditor.getColumnModel().setEditable(dataCol, false);
    gridEditor.getColumnModel().setRenderer(dataCol, setCellTextColorNormal);
  }
}
function setGridEditorCellEnable(gridEditor, collText){
  for (i=0;i<collText.length;i++){
    dataCol=collText[i];
    //gridEditor.getColumnModel().setEditable(dataCol, true);
    gridEditor.getColumnModel().setRenderer(dataCol, setCellColorEditable);
  }
}
function setCellTextColorNormal(value, meta, record){
  meta.css += ' gridEditorNormal';
  meta.style += 'background-color:rgba(255, 255, 255,0.1);';
  return value;
}
function setCellColorEditable(value, meta, store, record){
  meta.css += ' gridEditorNormal';
  meta.style += 'background-color:rgba(216, 250, 60,0.1);';
  return value;
}
function Search_Array(ArrayObj, SearchFor){
  let Found = false;
  for (let i = 0; i < ArrayObj.length; i++){
    if (ArrayObj[i] == SearchFor){
      return true;
      let Found = true;
      break;
    }
    else if ((i == (ArrayObj.length - 1)) && (!Found)){
      if (ArrayObj[i] != SearchFor){
        return false;
      }
    }
  }
}
