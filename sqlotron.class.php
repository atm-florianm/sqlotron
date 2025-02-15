<?php


if (!function_exists('str_contains')) {
	/**
	 * TODO: the function exists in php8 and is probably not implemented that way so this is a bad idea
	 * @param $haystack
	 * @param $needle
	 * @return bool
	 */
	function str_contains($haystack, $needle)
	{
		if (empty($needle) && $needle !== '0') return false;
		return strpos($haystack, $needle) !== false;
	}
}

class Token
{
	const KEYWORDS = [
		'select',
		'from',
		'left',
		'right',
		'full',
		'outer',
		'inner',
		'where',
		'having',
		'order',
		'by',
		'limit',
		'offset',
	];
	const OPERATORS = [
		'and', 'or'
	];
	var $type;
	var $value;
	var $group;
	var $lc;

	/**
	 * @param string $tokenGroup  Rough classification of the token
	 * @param string $value
	 */
	function __construct($tokenGroup, $value)
	{
		$this->group = $tokenGroup;
		$this->type = $this->_getTokenType($tokenGroup);
		$this->value = $value;
		$this->lc = strtolower($value);
	}

	/**
	 * TODO
	 * @return string
	 */
	function _getTokenType()
	{
		if ($this->group === 'singlequotestring' || $this->group === 'doublequotestring') {
			return 'string';
		} elseif ($this->group === 'symbol') {
			if (in_array(strtolower($this->value), self::KEYWORDS)) {
				return 'keyword';
			}
			if (in_array(strtolower($this->value), self::OPERATORS)) {
				return 'operator';
			}
			return 'symbol';
		} elseif ($this->group === 'numberliteral') {
			return 'number';
		} elseif ($this->group === 'paren') {
			return 'paren';
		} else {
			return $this->group;
		}
	}
}

class SQLBreakDown
{
	const DIGIT = '0123456789';
	const ALPHA = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
	const PAREN = '()';
	const SINGLEQUOTE = "'";
	const DOUBLEQUOTE = '"';
	const UNDERSCORE = '_';
	const PERIOD = '.';
	const OPERATOR = '+-*/=!<>';
	const WHITESPACE = " \t\r\n";
	const COMMA = ',';
	const SEMICOLON = ';';

	/**
	 * @param null|string $sql
	 * @throws Exception
	 */
	function __construct($sql = null)
	{
		$this->TOKENTYPES = [
			'undetermined' => [
				'starter' => '',
			],
			'singlequotestring' => [
				'starter' => "'",
			],
			'doublequotestring' => [
				'starter' => '"',
			],
			'backticksymbol' => [
				'starter' => '`',
			],
			'symbol' => [
				'starter' => self::ALPHA,
			],
			'operator' => [
				'starter' => self::OPERATOR,
			],
			'whitespace' => [
				'starter' => self::WHITESPACE,
			],
			'comma' => [
				'starter' => self::COMMA,
			],
			'semicolon' => [
				'starter' => self::SEMICOLON,
			],
			'paren' => [
				'starter' => self::PAREN,
			],
			'numberliteral' => [
				'starter' => self::DIGIT . self::PERIOD,
			],
		];
		if ($sql) $this->parse($sql);
	}

	/**
	 * @param string $sql
	 * @return Token[] array
	 * @throws Exception
	 */
	public function tokenize($sql)
	{
		// on convertit la chaîne SQL en un itérateur sur tableau de caractères
		// car plus pratique à faire circuler entre les fonctions.
		// $sqlChars = (new ArrayObject(str_split($sql)))->getIterator();
		// $this->sqlChars = $sqlChars;
		$this->sql = $sql;
		$this->cursor = 0;
		$this->tokens = [];
		while ($this->_current()) {
			$nextToken = $this->_getNextToken();
			$this->tokens[] = $nextToken;
		}
		return $this->tokens;
	}

	/**
	 * @param string|Token[]|null $sql
	 */
	public function parse($sql = null)
	{
		if ($sql !== null) {
			if (is_string($sql)) $this->tokenize($sql);
			elseif (is_array($sql)) $this->tokens = $sql;
			else throw new Exception('$sql must be either an array or a string');
		}
		$ret = [
			'select' =>   [],
			'from' =>     [],
			'where' =>    [],
			'having' =>   [],
			'orderby' =>  [],
			'limit' =>    [],
			'offset' =>   [],
			'end' =>      [],
		];
		$tokenN = 0;
		$findClosing = function () use (&$tokenN, &$findClosing) {
			$r = [];
			while (++$tokenN < count($this->tokens)) {
				$token = $this->tokens[$tokenN];
				$r[] = $token;
				if ($token->value === ')') {
					return $r;
				} elseif ($token->value === '(') {
					$r = array_merge($r, $findClosing());
				}
			}
			return $r;
		};
		$nextNonWhiteSpace = function () use (&$tokenN) {
			$n = $tokenN + 1;
			// on fait avancer $n tant qu'on a des espaces et qu'il reste des jetons
			while ($n < count($this->tokens) && $this->tokens[$n++]->type === 'whitespace');
			if ($n >= count($this->tokens)) return null; // plus de jetons
			return $this->tokens[$n-1];
		};

		$currentBucket = 'select';
		while ($tokenN < count($this->tokens)) {
			$token = $this->tokens[$tokenN];
			if ($token->type === 'whitespace') {
				$ret[$currentBucket][] = $token;
			} elseif ($token->lc === ';') {
				$currentBucket = 'end';
				$ret[$currentBucket][] = $token;
				break; // once query is finished, do not parse further.
			} elseif ($token->lc === 'select') {
				$ret[$currentBucket][] = $token;
			} elseif ($token->value === '(') {
				$ret[$currentBucket][] = $token;
				$ret[$currentBucket] = array_merge($ret[$currentBucket], $findClosing());
			} elseif ($token->lc === 'from') {
				$currentBucket = 'from';
				$ret[$currentBucket][] = $token;
			} elseif ($token->lc === 'where') {
				$currentBucket = 'where';
				$ret[$currentBucket][] = $token;
			} elseif ($token->lc === 'having') {
				$currentBucket = 'having';
				$ret[$currentBucket][] = $token;
			} elseif ($token->lc === 'order') {
				$x = $nextNonWhiteSpace();
				if ($x->lc === 'by') {$currentBucket = 'orderby';} // pas très élégant
				$ret[$currentBucket][] = $token;
			} elseif ($token->lc === 'limit') {
				$currentBucket = 'limit';
				$ret[$currentBucket][] = $token;
			} elseif ($token->lc === 'offset') {
				$currentBucket = 'offset';
				$ret[$currentBucket][] = $token;
			} else {
				$ret[$currentBucket][] = $token;
			}
			$tokenN++;
		}
		$tokenVal = function ($token) {
			return $token->value; };
		$this->buckets = $ret;
		foreach ($ret as $bucketName => $bucket) {
			$this->{$bucketName} = implode('', array_map($tokenVal, $bucket));
		}
	}

	/**
	 * @param string $c  One-character string
	 * @return string
	 */
	private function _determine($c)
	{
		foreach ($this->TOKENTYPES as $tokentype => $tokentypedef) {
			if (str_contains($tokentypedef['starter'], $c)) {
				return $tokentype;
			}
		}
		return 'undetermined';
	}

	/**
	 * @return Token
	 * @throws Exception
	 */
	private function _getNextToken()
	{
		$c = $this->_current();
		$curTokenGroup = $this->_determine($c);
		$curTokenValue = $c;
		switch ($curTokenGroup) {
			case 'undetermined':
				$curTokenValue .= $this->_next();
				return new Token($curTokenGroup, $curTokenValue);
			case 'singlequotestring': // TODO: factoriser + debug quand double (ça triple???)
				while ($this->_current() !== '') {
					$curTokenValue .= $c = $this->_next();
					if ($c === "'") {
						if ($this->_next() === "'") {
							$curTokenValue .= "'";
							$this->_next();
						} else {
							// $curTokenValue .= $c;
							// $this->_prev();
							return new Token($curTokenGroup, $curTokenValue);
						}
					} elseif ($c === "\\") {
						if ($this->_next() === "'") {
							$curTokenValue .= "'";
						} else {
							$curTokenValue .= $c;
							return new Token($curTokenGroup, $curTokenValue);
						}
					}
				}
				throw $this->_tokenError();
			case 'doublequotestring': // TODO factoriser avec singlequotestring
				while ($this->_current() !== '') {
					$curTokenValue .= $c = $this->_next();
					if ($c === '"') {
						if ($this->_next() === '"') {
							$curTokenValue .= '"';
						} else {
							// $curTokenValue .= $c;
							// $this->_prev();
							return new Token($curTokenGroup, $curTokenValue);
						}
					} elseif ($c === "\\") {
						if ($this->_next() === '"') {
							$curTokenValue .= '"';
						} else {
							$curTokenValue .= $c;
							return new Token($curTokenGroup, $curTokenValue);
						}
					}
				}
				throw $this->_tokenError();
			case 'backticksymbol':
				while ($this->_current() !== '') {
					$c = $this->_next();
					if ($c === '`') {
						$curTokenValue .= $c;
						$this->_next();
						return new Token($curTokenGroup, $curTokenValue);
					} else {
						$curTokenValue .= $c;
					}
				}
				throw $this->_tokenError();
			case 'symbol':
				while ($this->_current() !== '') {
					$c = $this->_next();
					if (str_contains(self::ALPHA . self::DIGIT . self::UNDERSCORE . self::PERIOD, $c)) {
						$curTokenValue .= $c;
					} else {
						return new Token($curTokenGroup, $curTokenValue);
					}
				}
				return new Token($curTokenGroup, $curTokenValue);
			case 'operator':
				while ($this->_current() !== '') {
					$c = $this->_next();
					if (str_contains(self::OPERATOR, $c)) {
						$curTokenValue .= $c;
					} else {
						return new Token($curTokenGroup, $curTokenValue);
					}
				}
				throw $this->_tokenError();
			case 'whitespace':
				while ($this->_current() !== '') {
					$c = $this->_next();
					if (str_contains(self::WHITESPACE, $c)) {
						$curTokenValue .= $c;
					} else {
						return new Token($curTokenGroup, $curTokenValue);
					}
				}
				return new Token($curTokenGroup, $curTokenValue);
			case 'semicolon':
				$this->_next();
				return new Token($curTokenGroup, $curTokenValue);
			case 'comma':
				$this->_next();
				return new Token($curTokenGroup, $curTokenValue);
			case 'paren':
				$this->_next();
				return new Token($curTokenGroup, $curTokenValue);
			case 'numberliteral':
				while ($this->_current() !== '') {
					$c = $this->_next();
					if (str_contains(self::DIGIT . self::PERIOD, $c)) {
						$curTokenValue .= $c;
					} else {
						return new Token($curTokenGroup, $curTokenValue);
					}
				}
				return new Token($curTokenGroup, $curTokenValue);
			default:
				throw $this->_tokenError();
		}
	}

	/**
	 * @return string
	 */
	private function _current()
	{
		$ret = mb_substr($this->sql, $this->cursor, 1);
		if (is_null($ret)) return '';
		return $ret;
	}

	/**
	 * @return string
	 */
	private function _next()
	{
		$this->cursor++;
		return $this->_current();
	}

	/**
	 * @return string
	 */
	private function _prev()
	{
		$this->cursor--;
		return $this->_current();
	}

	/**
	 * @return Exception
	 */
	private function _tokenError()
	{
		return new Exception('TokenizeError');
	}

	/**
	 * @param Token[] $tokenArray
	 * @return string
	 */
	public function toString($tokenArray)
	{
		return implode('', array_column($tokenArray, 'value'));
	}

	/**
	 * @return array
	 */
	public function serializeBuckets()
	{
		$ret = [];
		foreach ($this->buckets as $name => $bucket) {
			$ret[$name] = $this->{$name};
		}
		return $ret;
	}

	/**
	 * @return string
	 */
	public function getSQL()
	{
		return implode('', array_values($this->serializeBuckets()));
	}
	/**
	 * Returns a modified version of the parsed original query.
	 * The modifications are simply concatenated to the relevant portion of the
	 * query.
	 *
	 * Example:
	 * ```php
	 * $query = new SQLBreakDown("SELECT name FROM user WHERE id = 1");
	 * echo $query->getAlteredSQL([
	 *     'select' => ', supervisor.name AS supname',
	 *     'from' => 'LEFT JOIN user AS supervisor ON user.fk_sup = supervisor.rowid'
	 * ]);
	 * ```
	 * Will print:
	 *     SELECT name , supervisor.name AS supname FROM user LEFT JOIN user AS
	 *     supervisor ON user.fk_sup = supervisor.rowid WHERE id = 1

	 *
	 *
	 * @param array $modifications  possible keys: select, from, where, having,
	 *                              orderby, limit, offset
	 */
	public function getAlteredSQL($modifications)
	{
		$allowedBucketNames = [
			'select',
			'from',
			'where',
			'having',
			'orderby',
		];
		$b = $this->serializeBuckets();
		foreach ($modifications as $bucketName => $addition) {
			if (in_array($bucketName, $allowedBucketNames)) {
				$b[$bucketName] .= $addition . ' ';
			}
		}
		return implode('', array_values($b));
	}
}
