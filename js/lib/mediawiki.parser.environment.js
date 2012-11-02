"use strict";

var Util = require('./mediawiki.Util.js').Util;
var title = require('./mediawiki.Title.js'),
	$ = require( 'jquery' ),
	Title = title.Title,
	Namespace = title.Namespace;

var wikipedias = "en|de|fr|nl|it|pl|es|ru|ja|pt|zh|sv|vi|uk|ca|no|fi|cs|hu|ko|fa|id|tr|ro|ar|sk|eo|da|sr|lt|ms|eu|he|sl|bg|kk|vo|war|hr|hi|et|az|gl|simple|nn|la|th|el|new|roa-rup|oc|sh|ka|mk|tl|ht|pms|te|ta|be-x-old|ceb|br|be|lv|sq|jv|mg|cy|lb|mr|is|bs|yo|an|hy|fy|bpy|lmo|pnb|ml|sw|bn|io|af|gu|zh-yue|ne|nds|ku|ast|ur|scn|su|qu|diq|ba|tt|my|ga|cv|ia|nap|bat-smg|map-bms|wa|kn|als|am|bug|tg|gd|zh-min-nan|yi|vec|hif|sco|roa-tara|os|arz|nah|uz|sah|mn|sa|mzn|pam|hsb|mi|li|ky|si|co|gan|glk|ckb|bo|fo|bar|bcl|ilo|mrj|fiu-vro|nds-nl|tk|vls|se|gv|ps|rue|dv|nrm|pag|koi|pa|rm|km|kv|udm|csb|mhr|fur|mt|wuu|lij|ug|lad|pi|zea|sc|bh|zh-classical|nov|ksh|or|ang|kw|so|nv|xmf|stq|hak|ay|frp|frr|ext|szl|pcd|ie|gag|haw|xal|ln|rw|pdc|pfl|krc|crh|eml|ace|gn|to|ce|kl|arc|myv|dsb|vep|pap|bjn|as|tpi|lbe|wo|mdf|jbo|kab|av|sn|cbk-zam|ty|srn|kbd|lo|ab|lez|mwl|ltg|ig|na|kg|tet|za|kaa|nso|zu|rmy|cu|tn|chr|got|sm|bi|mo|bm|iu|chy|ik|pih|ss|sd|pnt|cdo|ee|ha|ti|bxr|om|ks|ts|ki|ve|sg|rn|dz|cr|lg|ak|tum|fj|st|tw|ch|ny|ff|xh|ng|ii|cho|mh|aa|kj|ho|mus|kr|hz";

var interwikiMap = {};
wikipedias.split('|').forEach( function (prefix) {
	interwikiMap[prefix] = 'http://' + prefix + '.wikipedia.org/w';
});

function Tracer(env) {
	this.env = env;
}
Tracer.prototype = {
	startPass: function(string) {
		if (this.env.trace) {
			console.warn("---- start: " + string + " ----");
		}
	},

	endPass: function(string) {
		if (this.env.trace) {
			console.warn("---- end  : " + string + " ----");
		}
	},

	traceToken: function(token, compact) {
		if (compact === undefined) {
			compact = true;
		}

		if (this.env.trace) {
			console.warn("T: " + token.toString(compact));
		}
	},

	output: function(string) {
		if (this.env.trace) {
			console.warn(string);
		}
	},

	outputChunk: function(chunk) {
		if (this.env.trace) {
			console.warn("---- <chunk:tokenized> ----");
			for (var i = 0, n = chunk.length; i < n; i++) {
				console.warn(chunk[i].toString());
			}
			console.warn("---- </chunk:tokenized> ----");
		}
	}
};

var MWParserEnvironment = function(opts) {
	var options = {
		tagHooks: {},
		parserFunctions: {},
		pageCache: {}, // @fixme use something with managed space
		debug: false,
		trace: false,
		wgScriptPath: "/wiki/",
		wgScript: "/wiki/index.php",
		wgUploadPath: "/wiki/images",
		wgScriptExtension: ".php",
		fetchTemplates: false,
		maxDepth: 40,
		pageName: 'Main page',
		interwikiMap: interwikiMap,
		interwikiRegexp: Object.keys(interwikiMap).join('|'),
		uid: 1
	};
	// XXX: this should be namespaced
	$.extend(options, opts);
	$.extend(this, options);

	this.setPageName( this.pageName );

	// Tracing object
	this.tracer = new Tracer(this);
};

MWParserEnvironment.prototype.setInterwiki = function (prefix, wgScript) {
	this.interwikiMap[prefix] = wgScript;
	this.interwikiRegexp = Object.keys(this.interwikiMap).join('|');
};
MWParserEnvironment.prototype.removeInterwiki = function (prefix) {
	delete this.interwikiMap[prefix];
	this.interwikiRegexp = Object.keys(this.interwikiMap).join('|');
};

// Outstanding page requests (for templates etc)
// Class-static
MWParserEnvironment.prototype.requestQueue = {};

MWParserEnvironment.prototype.setPageName = function ( pageName ) {
	this.pageName = pageName;
	// Construct a relative link prefix depending on the number of slashes in
	// pageName
	this.relativeLinkPrefix = '';
	var slashMatches = this.pageName.match(/\//g),
		numSlashes = slashMatches ? slashMatches.length : 0;
	if ( numSlashes ) {
		while ( numSlashes ) {
			this.relativeLinkPrefix += '../';
			numSlashes--;
		}
	} else {
		// Always prefix a ./ so that we don't have to escape colons. Those
		// would otherwise fool browsers into treating namespaces as
		// protocols.
		this.relativeLinkPrefix = './';
	}
};

MWParserEnvironment.prototype.getVariable = function( varname, options ) {
	//XXX what was the original author's intention?
	//something like this?:
	//  return this.options[varname];
	return this[varname];
};

MWParserEnvironment.prototype.setVariable = function( varname, value, options ) {
	this[varname] = value;
};

/**
 * @return MWParserFunction
 */
MWParserEnvironment.prototype.getParserFunction = function( name ) {
	if (name in this.parserFunctions) {
		return new this.parserFunctions[name]( this );
	} else {
		return null;
	}
};

/**
 * @return MWParserTagHook
 */
MWParserEnvironment.prototype.getTagHook = function( name ) {
	if (name in this.tagHooks) {
		return new this.tagHooks[name](this);
	} else {
		return null;
	}
};


MWParserEnvironment.prototype.makeTitleFromPrefixedText = function ( text ) {
	text = this.normalizeTitle( text );
	var nsText = text.split( ':', 1 )[0];
	if ( nsText && nsText !== text ) {
		var _ns = new Namespace(0);
		var ns = _ns._defaultNamespaceIDs[ nsText.toLowerCase() ];
		//console.warn( JSON.stringify( [ nsText, ns ] ) );
		if ( ns !== undefined ) {
			return new Title( text.substr( nsText.length + 1 ), ns, nsText, this );
		} else {
			return new Title( text, 0, '', this );
		}
	} else {
		return new Title( text, 0, '', this );
	}
};


// XXX: move to Title!
MWParserEnvironment.prototype.normalizeTitle = function( name ) {
	if (typeof name !== 'string') {
		throw new Error('nooooooooo not a string');
	}
	var forceNS, self = this;
	if ( name.substr( 0, 1 ) === ':' ) {
		forceNS = ':';
		name = name.substr(1);
	} else {
		forceNS = '';
	}


	name = name.trim().replace(/[\s_]+/g, '_');

	// Implement int: as alias for MediaWiki:
	if ( name.substr( 0, 4 ) === 'int:' ) {
		name = 'MediaWiki:' + name.substr( 4 );
	}

	// FIXME: Generalize namespace case normalization
	if ( name.substr( 0, 10 ).toLowerCase() === 'mediawiki:' ) {
		name = 'MediaWiki:' + name.substr( 10 );
	}

	function upperFirst( s ) { return s.substr( 0, 1 ).toUpperCase() + s.substr(1); }

	function splitNS ( ) {
		var nsMatch = name.match( /^([a-zA-Z\-]+):/ ),
			ns = nsMatch && nsMatch[1] || '';
		if( ns !== '' && ns !== name ) {
			if ( self.interwikiMap[ns.toLowerCase()] ) {
				forceNS += ns + ':';
				name = name.substr( nsMatch[0].length );
				splitNS();
			} else {
				name = upperFirst( ns ) + ':' + upperFirst( name.substr( ns.length + 1 ) );
			}
		} else {
			name = upperFirst( name );
		}
	}
	splitNS();
	//name = name.split(':').map( upperFirst ).join(':');
	//if (name === '') {
	//	throw new Error('Invalid/empty title');
	//}
	return forceNS + name;
};

/**
 * @fixme do this for real eh
 */
MWParserEnvironment.prototype.resolveTitle = function( name, namespace ) {
	// Resolve subpages
	var relUp = name.match(/^(\.\.\/)+/);
	if ( relUp ) {
		var levels = relUp[0].length / 3,
			titleBits = this.pageName.split(/\//),
			newBits = titleBits.slice(0, titleBits.length - levels);
		if ( name !== relUp[0] ) {
			newBits.push( name.substr(levels * 3) );
		}
		name = newBits.join('/');
		//console.log( relUp, name );
	}

	if ( name.length && name[0] === '/' ) {
		name = this.normalizeTitle( this.pageName ) + name;
	}
	// FIXME: match against proper list of namespaces
	if ( name.indexOf(':') === -1 && namespace ) {
		// hack hack hack
		name = namespace + ':' + this.normalizeTitle( name );
	}
	// Strip leading ':'
	if (name[0] === ':') {
		name = name.substr( 1 );
	}

	return name;
};

/**
 * Simple debug helper
 */
MWParserEnvironment.prototype.dp = function ( ) {
	if ( this.debug ) {
		if ( arguments.length > 1 ) {
			try {
				console.warn( JSON.stringify( arguments, null, 2 ) );
			} catch ( e ) {
				console.trace();
				console.warn( e );
			}
		} else {
			console.warn( arguments[0] );
		}
	}
};

/**
 * Even simpler debug helper that always prints..
 */
MWParserEnvironment.prototype.ap = function ( ) {
	if ( arguments.length > 1 ) {
		try {
			console.warn( JSON.stringify( arguments, null, 2 ) );
		} catch ( e ) {
			console.warn( e );
		}
	} else {
		console.warn( arguments[0] );
	}
};
/**
 * Simple debug helper, trace-only
 */
MWParserEnvironment.prototype.tp = function ( ) {
	if ( this.debug || this.trace ) {
		if ( arguments.length > 1 ) {
			console.warn( JSON.stringify( arguments, null, 2 ) );
		} else {
			console.warn( arguments[0] );
		}
	}
};

/**
 * Generate a UID
 */
MWParserEnvironment.prototype.generateUID = function () {
	return this.uid++;
};


if (typeof module === "object") {
	module.exports.MWParserEnvironment = MWParserEnvironment;
}

