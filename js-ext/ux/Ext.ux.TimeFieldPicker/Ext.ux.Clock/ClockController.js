/**
 * @class Ext.ux.ClockController
 * @extends Ext.util.Observable
 */
/**
 * @constructor
 * @param Object
 */
Ext.ux.ClockController=function(config) {
	Ext.apply(this, config || {});
	Ext.ux.ClockController.superclass.constructor.apply(this, arguments);
	
	if(this.runTask) {
		this.task=Ext.TaskMgr.start({
			run:		this.render,
			scope:		this,
			interval:	this.taskInterval
		});
	}
	
	this.render();
}
Ext.extend(Ext.ux.ClockController, Ext.util.Observable, {
	/**
	 * @var Curly.Canvas Das fr Zeichenvorgnge zu verwendende Canvas-Objekt.
	 */
	canvas: null,
	/**
	 * @var integer X-Koordinate des Mittelpunktes der Uhr.
	 */
	x: 0,
	/**
	 * @var integer Y-Koordinate des Mittelpunktes der Uhr.
	 */
	y: 0,
	/**
	 * @var integer Gre der Uhr.
	 */
	size: 30,
	/**
	 * @var integer Task-Interval
	 */
	taskInterval: 500,
	/**
	 * @var boolean Flag, ob dieses Objekt das Canvas-Objekt vor dem Rendern
	 * leeren soll.
	 */
	autoClear: true,
	/**
	 * @var boolean Flag, ob dieses Objekt mit Hilfe eines Taskrunners
	 * regelmig neu gerendert werden soll.
	 */
	runTask: true,
	/**
	 * @var Object Der im TaskRunner eingefgte Task.
	 */
	task: null,
	/**
	 * @var Object Ein Objekt der Form {h: H, m: M, s: S} fr die zu rendernde Uhrzeit.
	 * Standardwert ist die jeweils aktuelle Uhrzeit.
	 */
	date: null,
	/**
	 * @var Object Canvas-Zustand whrend des Zeichnen des Auenkreises.
	 */
	outerArcState: {
		strokeStyle:		'black',
		lineWidth:			1
	},
	/**
	 * @var Object Canvas-Zustand whrend des Zeichnen der Skala.
	 */
	scaleState: {
		lineWidth:			1
	},
	/**
	 * @var integer Lnge der Skalaangaben zu 3, 6, 9 und 12 Uhr im Verhltnis
	 * zu der Gesamtgre.
	 */
	scaleInnerSize: 4,
	/**
	 * @var integer Lnge der Skalaangaben zu 1, 2, 4, 5, 7, 8, 10 und 11 Uhr.
	 */
	scaleOuterSize: 8,
	/**
	 * @var Object Canvas-Zustand whrend des Zeichnen des Stundenzeiger.
	 */
	hourIndicatorState: {
		lineWidth:			3
	},
	/**
	 * @var Object Canvas-Zustand whrend des Zeichnen des Minutenzeiger.
	 */
	minuteIndicatorState: {
		lineWidth:			2
	},
	/**
	 * @var Object Canvas-Zustand whrend des Zeichnen des Sekundenzeiger.
	 */
	secondIndicatorState: {
		lineWidth:			1
	},
	/**
	 * @var integer Wert fr die Berechnung des Stundenzeigers proportional zu
	 * der size-Angabe. Die verwendete Zeigerlnge entspricht dem size-Wert
	 * verringert um diesen Wert.
	 */
	hourIndicatorShorten: 10,
	/**
	 * @var integer Wert fr die Berechnung des Minutenzeigers proportional zu
	 * der size-Angabe. Die verwendete Zeigerlnge entspricht dem size-Wert
	 * verringert um diesen Wert.
	 */
	minuteIndicatorShorten: 2,
	/**
	 * @var integer Wert fr die Berechnung des Sekundenzeigers proportional zu
	 * der size-Angabe. Die verwendete Zeigerlnge entspricht dem size-Wert
	 * verringert um diesen Wert.
	 */
	secondIndicatorShorten: 1,
	/**
	 * Rendert dieses Objekt.
	 * 
	 * @return void
	 */
	render: function() {
		if(this.autoClear) {
			this.canvas.clear();
		}
		
		this.renderOuterArc();
		this.renderScale();
		this.renderIndicator();
	},
	// private
	renderOuterArc: function() {
		this.canvas.
			overwriteState(this.outerArcState).
			draw(new Curly.ArcLine(this.x, this.y, this.size));
	},
	// private
	renderScale: function() {
		var add=Math.PI/6, angle=0, innerSize, ax, ay, path=this.canvas.path();
		this.canvas.overwriteState(this.scaleState).applyState();
		for(var i=0; i<12; i++) {
			angle+=add;
			if(i%3===2) {
				innerSize=this.size-this.size/this.scaleInnerSize;
			}
			else {
				innerSize=this.size-this.size/this.scaleOuterSize;
			}
			
			x=Math.sin(angle);
			y=Math.cos(angle);
			path.
				moveTo(innerSize*x+this.x, innerSize*y+this.y).
				lineTo(this.size*x+this.x, this.size*y+this.y);
		}
		path.
			moveTo(this.x, this.y).
			arc(1, 0, Math.PI*2, false).
			draw();
	},
	// private
	renderIndicator: function() {
		var date=this.date;
		if(date===null) {
			date=new Date();
		}
		if(date instanceof Date) {
			date={
				h: date.getHours(),
				m: date.getMinutes(),
				s: date.getSeconds()
			}
		}
		
		this.canvas.overwriteState(this.hourIndicatorState).applyState();
		this.indicator(this.size-this.hourIndicatorShorten, -Math.PI*((date.h%12+6)/6));
		
		this.canvas.overwriteState(this.minuteIndicatorState).applyState();
		this.indicator(this.size-this.minuteIndicatorShorten, -Math.PI*((date.m+30)/30));
		
		this.canvas.overwriteState(this.secondIndicatorState).applyState();
		this.indicator(this.size-this.secondIndicatorShorten, -Math.PI*((date.s+30)/30));
	},
	// private
	indicator: function(size, angle) {
		var x=Math.sin(angle);
		var y=Math.cos(angle);
		this.canvas.
			path(this.x, this.y).
			lineTo(size*x+this.x, size*y+this.y).
			draw();
	}
});
