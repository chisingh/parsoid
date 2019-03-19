<?php
declare( strict_types = 1 );

namespace Parsoid\Wt2Html\TT;

use Parsoid\Utils\TokenUtils;
use Parsoid\Utils\WTUtils;
use Parsoid\Tokens\EndTagTk;
use Parsoid\Tokens\EOFTk;
use Parsoid\Tokens\NlTk;
use Parsoid\Tokens\TagTk;
use Parsoid\Tokens\Token;

/**
PRE handling.

PRE-handling relies on the following 5-state FSM.

States
------
```
SOL           -- start-of-line
(white-space, comments, meta-tags are all SOL transparent)
PRE           -- we might need a pre-block
(if we enter the PRE_COLLECT state)
PRE_COLLECT   -- we will need to generate a pre-block and are collecting
content for it.
MULTILINE_PRE -- we might need to extend the pre-block to multiple lines.
(depending on whether we see a white-space tok or not)
IGNORE        -- nothing to do for the rest of the line.
```

Transitions
-----------

In the transition table below, purge is just a shortcut for:
"pass on collected tokens to the callback and reset (getResultAndReset)"
```
+ --------------+-----------------+---------------+--------------------------+
| Start state   |     Token       | End state     |  Action                  |
+ --------------+-----------------+---------------+--------------------------+
| SOL           | --- nl      --> | SOL           | purge                    |
| SOL           | --- eof     --> | SOL           | purge                    |
| SOL           | --- ws      --> | PRE           | save whitespace token(##)|
| SOL           | --- sol-tr  --> | SOL           | TOKS << tok              |
| SOL           | --- other   --> | IGNORE        | purge                    |
+ --------------+-----------------+---------------+--------------------------+
| PRE           | --- nl      --> | SOL           | purge                    |
| PRE           |  html-blk tag   | IGNORE        | purge                    |
|               |  wt-table tag   |               |                          |
| PRE           | --- eof     --> | SOL           | purge                    |
| PRE           | --- sol-tr  --> | PRE           | SOL-TR-TOKS << tok       |
| PRE           | --- other   --> | PRE_COLLECT   | TOKS = SOL-TR-TOKS + tok |
+ --------------+-----------------+---------------+--------------------------+
| PRE_COLLECT   | --- nl      --> | MULTILINE_PRE | save nl token            |
| PRE_COLLECT   | --- eof     --> | SOL           | gen-pre                  |
| PRE_COLLECT   | --- blk tag --> | IGNORE        | gen-prepurge (#)         |
| PRE_COLLECT   | --- any     --> | PRE_COLLECT   | TOKS << tok              |
+ --------------+-----------------+---------------+--------------------------+
| MULTILINE_PRE | --- nl      --> | SOL           | gen-pre                  |
| MULTILINE_PRE | --- eof     --> | SOL           | gen-pre                  |
| MULTILINE_PRE | --- ws      --> | PRE_COLLECT   | pop saved nl token (##)  |
|               |                 |               | TOKS = SOL-TR-TOKS + tok |
| MULTILINE_PRE | --- sol-tr  --> | MULTILINE_PRE | SOL-TR-TOKS << tok       |
| MULTILINE_PRE | --- any     --> | IGNORE        | gen-pre                  |
+ --------------+-----------------+---------------+--------------------------+
| IGNORE        | --- nl      --> | SOL           | purge                    |
| IGNORE        | --- eof     --> | SOL           | purge                    |
+ --------------+-----------------+---------------+--------------------------+

# If we've collected any tokens from previous lines, generate a pre. This
line gets purged.

## In these states, check if the whitespace token is a single space or has
additional chars (white-space or non-whitespace) -- if yes, slice it off
and pass it through the FSM.
*/
class PreHandler extends TokenHandler {
	// FSM states
	const STATE_SOL = 1;
	const STATE_PRE = 2;
	const STATE_PRE_COLLECT = 3;
	const STATE_MULTILINE_PRE = 4;
	const STATE_IGNORE = 5;

	private $state;
	private $lastNlTk;
	private $preTSR;
	private $tokens;
	private $preCollectCurrentLine;
	private $preWSToken;
	private $multiLinePreWSToken;
	private $solTransparentTokens;

	/**
	 * debug string output of FSM states
	 * @return array
	 */
	private static function stateStr(): array {
		return [
			1 => 'sol        ',
			2 => 'pre        ',
			3 => 'pre_collect',
			4 => 'multiline  ',
			5 => 'ignore     '
		];
	}

	/**
	 * Class constructor
	 *
	 * @param object $manager manager environment
	 * @param array $options options
	 */
	public function __construct( $manager, array $options ) {
		parent::__construct( $manager, $options );
		$this->resetState( $options );
	}

	// FIXME: Needed because of shared pipelines in parser tests
	/**
	 * Resets the state based on options parameter
	 *
	 * @param array $opts
	 */
	public function resetState( array $opts ): void {
		if ( !empty( $opts['inlineContext'] ) || !empty( $opts['inPHPBlock'] ) ) {
			$this->disabled = true;
		} else {
			$this->disabled = false;
			$this->reset( true );
		}
	}

	/**
	 * Resets the FSM state with optional any handler enabled
	 *
	 * @param bool $enableAnyHandler
	 */
	private function reset( bool $enableAnyHandler ): void {
		$this->state = self::STATE_SOL;
		$this->lastNlTk = null;
		// Initialize to zero to deal with indent-pre
		// on the very first line where there is no
		// preceding newline to initialize this.
		$this->preTSR = 0;
		$this->tokens = [];
		$this->preCollectCurrentLine = [];
		$this->preWSToken = null;
		$this->multiLinePreWSToken = null;
		$this->solTransparentTokens = [];
		if ( $enableAnyHandler ) {
			$this->onAnyEnabled = true;
		}
	}

	/**
	 * Switches the FSM to STATE_IGNORE
	 */
	private function moveToIgnoreState(): void {
		$this->onAnyEnabled = false;
		$this->state = self::STATE_IGNORE;
	}

	/**
	 * Pops the last new line from the $ret array
	 *
	 * @param array &$ret
	 */
	private function popLastNL( array &$ret ): void {
		if ( $this->lastNlTk ) {
			$ret[] = $this->lastNlTk;
			$this->lastNlTk = null;
		}
	}

	/**
	 * Removes multiline-pre-ws token when multi-line pre has been specified
	 */
	private function resetPreCollectCurrentLine(): void {
		if ( count( $this->preCollectCurrentLine ) > 0 ) {
			$this->tokens = array_merge( $this->tokens, $this->preCollectCurrentLine );
			$this->preCollectCurrentLine = [];
			// Since the multi-line pre materialized, the multiline-pre-ws token
			// should be discarded so that it is not emitted after <pre>..</pre>
			// is generated (see processPre).
			$this->multiLinePreWSToken = null;
		}
	}

	/**
	 * If a blocking token sequence is encountered with collecting, cleanup state
	 *
	 * @param Token $token
	 * @return array
	 */
	private function encounteredBlockWhileCollecting( Token $token ): array {
		$env = $this->manager->env;
		$ret = [];
		$mlp = null;

		// we remove any possible multiline ws token here and save it because
		// otherwise the propressPre below would add it in the wrong place
		if ( $this->multiLinePreWSToken ) {
			$mlp = $this->multiLinePreWSToken;
			$this->multiLinePreWSToken = null;
		}

		$i = count( $this->tokens );
		if ( $i > 0 ) {
			$i--;
			while ( $i > 0 && TokenUtils::isSolTransparent( $env, $this->tokens[$i] ) ) {
				$i--;
			}
			$solToks = array_splice( $this->tokens, $i );
			$this->lastNlTk = array_shift( $solToks );
			// assert( $this->lastNlTk && get_class( $this->lastNlTk ) === NlTk::class );
			$ret = array_merge( $this->processPre( null ), $solToks );
		}

		if ( $this->preWSToken || $mlp ) {
			$ret[] = !is_null( $this->preWSToken ) ? $this->preWSToken : $mlp;
			$this->preWSToken = null;
		}

		$this->resetPreCollectCurrentLine();
		return array_merge( $ret, $this->getResultAndReset( $token ) );
	}

	/**
	 * Get results and cleanup state
	 *
	 * @param Token|string $token
	 * @return array
	 */
	private function getResultAndReset( $token ): array {
		$this->popLastNL( $this->tokens );

		$ret = $this->tokens;
		if ( $this->preWSToken ) {
			$ret[] = $this->preWSToken;
			$this->preWSToken = null;
		}
		if ( count( $this->solTransparentTokens ) > 0 ) {
			$ret = array_merge( $ret, $this->solTransparentTokens );
			$this->solTransparentTokens = [];
		}
		$ret[] = $token;
		$this->tokens = [];
		$this->multiLinePreWSToken = null;

		return $ret;
	}

	/**
	 * Process a pre
	 *
	 * @param Token|string $token
	 * @return array
	 */
	private function processPre( $token ): array {
		$ret = [];

		// pre only if we have tokens to enclose
		if ( count( $this->tokens ) > 0 ) {
			$da = null;
			if ( $this->preTSR !== -1 ) {
				$da = (object)[ 'tsr' => [ $this->preTSR, $this->preTSR + 1 ] ];
			}
			$ret = array_merge( [ new TagTk( 'pre', [], $da ) ], $this->tokens, [ new EndTagTk( 'pre' ) ] );
		}

		// emit multiline-pre WS token
		if ( $this->multiLinePreWSToken ) {
			$ret[] = $this->multiLinePreWSToken;
			$this->multiLinePreWSToken = null;
		}
		$this->popLastNL( $ret );

		// sol-transparent toks
		$ret = array_merge( $ret, $this->solTransparentTokens );

		// push the the current token
		if ( $token !== null ) {
			$ret[] = $token;
		}

		// reset!
		$this->solTransparentTokens = [];
		$this->tokens = [];

		return $ret;
	}

	/**
	 * Initialize a pre TSR
	 *
	 * @param NlTk $nltk
	 * @return int
	 */
	private function initPreTSR( NlTk $nltk ): int {
		$da = $nltk->dataAttribs;
		// tsr[1] can never be zero, so safe to use da.tsr[1] to check for null/undefined
		return ( $da && isset( $da->tsr ) && $da->tsr[ 1 ] !== null ) ? $da->tsr[ 1 ] : -1;
	}

	/**
	 * Handler onNewLine processing
	 *
	 * @param NlTk $token
	 * @return array
	 */
	public function onNewline( NlTk $token ): array {
		$env = $this->manager->env;

		$env->log( 'trace/pre', $this->manager->pipelineId, 'NL    |',
			self::stateStr()[ $this->state ], '|',
			function () use ( $token ) {
				return json_encode( $token );
			}
		);

		// Whenever we move into SOL-state, init preTSR to
		// the newline's tsr[1].  This will later be  used
		// to assign 'tsr' values to the <pre> token.

		$ret = [];
		// See TokenHandler's documentation for the onAny handler
		// for what this flag is about.
		$skipOnAny = false;
		switch ( $this->state ) {
			case self::STATE_SOL:
			$ret = $this->getResultAndReset( $token );
			$skipOnAny = true;
			$this->preTSR = self::initPreTSR( $token );
			break;

			case self::STATE_PRE:
			$ret = $this->getResultAndReset( $token );
			$skipOnAny = true;
			$this->preTSR = self::initPreTSR( $token );
			$this->state = self::STATE_SOL;
			break;

			case self::STATE_PRE_COLLECT:
			$this->resetPreCollectCurrentLine();
			$this->lastNlTk = $token;
			$this->state = self::STATE_MULTILINE_PRE;
			break;

			case self::STATE_MULTILINE_PRE:
			$this->preWSToken = null;
			$this->multiLinePreWSToken = null;
			$ret = $this->processPre( $token );
			$skipOnAny = true;
			$this->preTSR = self::initPreTSR( $token );
			$this->state = self::STATE_SOL;
			break;

			case self::STATE_IGNORE:
			$ret = [ $token ];
			$skipOnAny = true;
			$this->reset( true );
			$this->preTSR = self::initPreTSR( $token );
			break;
		}

		$env->log( 'debug/pre', $this->manager->pipelineId, 'saved :', $this->tokens );
		$env->log( 'debug/pre', $this->manager->pipelineId, '---->  ',
			function () use ( $ret ) {
				return json_encode( $ret );
			}
		);

		return [ 'tokens' => $ret, 'skipOnAny' => $skipOnAny ];
	}

	/**
	 * Handler onEnd processing
	 *
	 * @param EOFTk $token
	 * @return Token|array
	 */
	public function onEnd( EOFTk $token ) {
		if ( !$this->onAnyEnabled ) {
			return $token;
		}

		$this->manager->env->log( 'trace/pre', $this->manager->pipelineId, 'eof   |',
			self::stateStr()[ $this->state ], '|',
			function () use ( $token ) {
				return json_encode( $token );
			}
		);

		$ret = [];
		$skipOnAny = false;
		switch ( $this->state ) {
			case self::STATE_SOL:
			case self::STATE_PRE:
				$ret = $this->getResultAndReset( $token );
				$skipOnAny = true;
				break;

			case self::STATE_PRE_COLLECT:
			case self::STATE_MULTILINE_PRE:
				$this->preWSToken = null;
				$this->multiLinePreWSToken = null;
				$this->resetPreCollectCurrentLine();
				$ret = $this->processPre( $token );
				$skipOnAny = true;
				break;
		}

		// reset for next use of this pipeline!
		$this->reset( true );

		$this->manager->env->log( 'debug/pre', $this->manager->pipelineId, 'saved :', $this->tokens );
		$this->manager->env->log( 'debug/pre', $this->manager->pipelineId, '---->  ',
			function () use ( $ret ){
				return json_encode( $ret );
			}
		);

		return [ 'tokens' => $ret, 'skipOnAny' => $skipOnAny ];
	}

	/**
	 * Get updated pre TSR value
	 *
	 * @param int $tsr
	 * @param Token|string $token
	 * @return int
	 */
	private function getUpdatedPreTSR( int $tsr, $token ): int {
		$tc = TokenUtils::getTokenType( $token );
		if ( $tc === 'CommentTk' ) {
			// comment length has 7 added for "<!--" and "-->" deliminters
			// (see WTUtils.decodedCommentLength() -- but that takes a node not a token)
			$tsr = isset( $token->dataAttribs->tsr ) ? $token->dataAttribs->tsr[ 1 ] :
				( ( $tsr === -1 ) ? -1 : count( WTUtils::decodeComment( $token->value ) ) + 7 + $tsr );
		} elseif ( $tc === 'SelfclosingTagTk' ) {
			// meta-tag (cannot compute)
			$tsr = -1;
		} elseif ( $tsr !== -1 ) {
			// string
			$tsr += mb_strlen( $token );
		}
		return $tsr;
	}

	/**
	 * Handle onAny processing
	 *
	 * @param Token|string $token
	 * @return array
	 */
	public function onAny( $token ): array {
		$env = $this->manager->env;

		$env->log( 'trace/pre', $this->manager->pipelineId, 'any   |', $this->state, ':',
			self::stateStr()[ $this->state ], '|',
			function () use ( $token ) {
				return json_encode( $token );
			}
		);

		if ( $this->state === self::STATE_IGNORE ) {
			$env->log( 'error', function () use ( $token ) {
				return '!ERROR! IGNORE! Cannot get here: ' . json_encode( $token );
			} );
			return $token;
		}

		$skipOnAny = false;
		$ret = [];
		$tc = TokenUtils::getTokenType( $token );
		if ( $tc === 'EOFTk' ) {
			switch ( $this->state ) {
				case self::STATE_SOL:
				case self::STATE_PRE:
					$ret = $this->getResultAndReset( $token );
					$skipOnAny = true;
					break;

				case self::STATE_PRE_COLLECT:
				case self::STATE_MULTILINE_PRE:
					$this->preWSToken = null;
					$this->multiLinePreWSToken = null;
					$this->resetPreCollectCurrentLine();
					$ret = $this->processPre( $token );
					$skipOnAny = true;
					break;
			}

			// reset for next use of this pipeline!
			$this->reset( false );
		} else {
			switch ( $this->state ) {
				case self::STATE_SOL:
				if ( ( $tc === 'string' ) && preg_match( '/^ /', $token ) ) {
					$ret = $this->tokens;
					$this->tokens = [];
					$this->preWSToken = $token[ 0 ];
					$this->state = self::STATE_PRE;
					if ( !preg_match( '/^ $/', $token ) ) {
						// Treat everything after the first space
						// as a new token
						$this->onAny( mb_substr( $token, 1 ) );
					}
				} elseif ( TokenUtils::isSolTransparent( $env, $token ) ) {
					// continue watching ...
					// update pre-tsr since we haven't transitioned to PRE yet
					$this->preTSR = $this->getUpdatedPreTSR( $this->preTSR, $token );
					$this->tokens[] = $token;
				} else {
					$ret = $this->getResultAndReset( $token );
					$skipOnAny = true;
					$this->moveToIgnoreState();
				}
				break;

				case self::STATE_PRE:
				if ( TokenUtils::isSolTransparent( $env, $token ) ) { // continue watching
					$this->solTransparentTokens[] = $token;
				} elseif ( TokenUtils::isTableTag( $token ) ||
					( TokenUtils::isHTMLTag( $token ) && TokenUtils::isBlockTag( $token->getName() ) )
				) {
					$ret = $this->getResultAndReset( $token );
					$skipOnAny = true;
					$this->moveToIgnoreState();
				} else {
					$this->preCollectCurrentLine = $this->solTransparentTokens;
					$this->preCollectCurrentLine[] = $token;
					$this->solTransparentTokens = [];
					$this->state = self::STATE_PRE_COLLECT;
				}
				break;

				case self::STATE_PRE_COLLECT:
				if ( $tc !== 'string' && TokenUtils::isBlockTag( $token->getName() ) ) {
					$ret = $this->encounteredBlockWhileCollecting( $token );
					$skipOnAny = true;
					$this->moveToIgnoreState();
				} else {
					// nothing to do .. keep collecting!
					$this->preCollectCurrentLine[] = $token;
				}
				break;

				case self::STATE_MULTILINE_PRE:
				if ( ( $tc === 'string' ) && preg_match( '/^ /', $token ) ) {
					$this->popLastNL( $this->tokens );
					$this->state = self::STATE_PRE_COLLECT;
					$this->preWSToken = null;

					// Pop buffered sol-transparent tokens
					$this->tokens = array_merge( $this->tokens, $this->solTransparentTokens );
					$this->solTransparentTokens = [];

					// check if token is single-space or more
					$this->multiLinePreWSToken = $token[ 0 ];
					if ( !preg_match( '/^ $/', $token ) ) {
						// Treat everything after the first space as a new token
						$this->onAny( mb_substr( $token, 1 ) );
					}
				} elseif ( TokenUtils::isSolTransparent( $env, $token ) ) { // continue watching
					$this->solTransparentTokens[] = $token;
				} else {
					$ret = $this->processPre( $token );
					$skipOnAny = true;
					$this->moveToIgnoreState();
				}
				break;
			}
		}

		$env->log( 'debug/pre', $this->manager->pipelineId, 'saved :', $this->tokens );
		$env->log( 'debug/pre', $this->manager->pipelineId, '---->  ',
			function () use ( $ret ) {
				return json_encode( $ret );
			}
		);

		return [ 'tokens' => $ret, 'skipOnAny' => $skipOnAny ];
	}
}