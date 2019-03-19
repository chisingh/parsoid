<?php
declare( strict_types = 1 );

namespace Parsoid\Wt2Html\TT;

use Parsoid\Tokens\Token;
use Parsoid\Tokens\KV;
use Parsoid\Tokens\SelfclosingTagTk;

/**
 * Handler for behavior switches, like '__TOC__' and similar.
 *
 * @class
 * @extends module:wt2html/tt/TokenHandler
 */
class BehaviorSwitchHandler extends TokenHandler {
	/**
	 * Class constructor
	 *
	 * @param object $manager manager environment
	 * @param array $options options
	 */
	public function __construct( $manager, array $options ) {
		parent::__construct( $manager, $options );
	}

	/**
	 * Main handler.
	 * See {@link TokenTransformManager#addTransform}'s transformation parameter.
	 *
	 * @param Token $token
	 * @return array
	 */
	public function onBehaviorSwitch( Token $token ): array {
		$env = $this->manager->env;
		$magicWord = $env->getSiteConfig()->magicWordCanonicalName( $token->attribs[ 0 ]->v );
		$env->setVariable( $magicWord, true );
		$metaToken = new SelfclosingTagTk(
			'meta',
			[ new KV( 'property', 'mw:PageProp/' . $magicWord ) ],
			clone $token->dataAttribs   // shallow clone dataAttribs
		);

		return [ 'tokens' => [ $metaToken ] ];
	}

	/**
	 * Handle onTag processing
	 *
	 * @param Token $token
	 * @return Token
	 */
	public function onTag( Token $token ) {
		$name = is_string( $token ) ? $token : $token->getName();
		return ( $name === 'behavior-switch' ) ? $this->onBehaviorSwitch( $token ) : $token;
	}
}