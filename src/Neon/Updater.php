<?php

declare(strict_types=1);

namespace Nette\Neon;

use Nette\Neon\Node\ArrayItemNode;
use Nette\Neon\Node\ArrayNode;


/*
normalization must take place and this notation must be removed:

- a:
  b:

a:
- a
- b

*/

/** @internal */
class Updater
{
	/** @var TokenStream */
	private $tokens;

	/** @var Node */
	private $node;

	/** @var string[] */
	private $replacements;

	/** @var string[] */
	private $appends;


	public function __construct(string $input)
	{
		$this->tokens = (new Lexer)->tokenize($input);
		$this->node = (new Parser)->parse($this->tokens);
	}


	public function getNodeClone(): Node
	{
		return (new Traverser)->traverse($this->node, function (Node $node) {
			$dolly = clone $node;
			$dolly->data['originalNode'] = $node;
			return $dolly;
		});
	}


	public function updateValue($newValue): string
	{
		$newNode = (new Encoder)->valueToNode($newValue);
		$this->guessOriginalNodes($this->node, $newNode);
		return $this->updateNode($newNode);
	}


	public function updateNode(Node $newNode): string
	{
		$this->replacements = $this->appends = [];
		$this->replaceNode($this->node, $newNode);
		$res = '';
		foreach ($this->tokens->getTokens() as $i => $token) {
			$res .= $this->appends[$i] ?? '';
			$res .= $this->replacements[$i] ?? $token->value;
		}
		return $res;
	}


	public function guessOriginalNodes(Node $oldNode, Node $newNode): void
	{
		if ($oldNode instanceof ArrayNode && $newNode instanceof ArrayNode && $oldNode->indentation !== null) {
			$newNode->data['originalNode'] = $oldNode;
			$differ = new Differ(function (ArrayItemNode $old, ArrayItemNode $new) {
				if ($old->key || $new->key) {
					return ($old->key ? $old->key->toValue() : null) === ($new->key ? $new->key->toValue() : null);
				} else {
					return $old->value->toValue() === $new->value->toValue();
				}
			});
			$steps = $differ->diff($oldNode->items, $newNode->items);
			foreach ($steps as [$type, $oldItem, $newItem]) {
				if ($type === $differ::KEEP) { // keys are same
					$newItem->data['originalNode'] = $oldItem;
					// TODO: original for keys?
					$this->guessOriginalNodes($oldItem->value, $newItem->value);
				}
			}

		} elseif ($oldNode->toValue() === $newNode->toValue()) {
			$newNode->data['originalNode'] = $oldNode;
		}
	}


	private function replaceNode(Node $oldNode, Node $newNode, string $indentation = null): void
	{
		// assumes that $oldNode->data['originalNode'] === $newNode
		if ($oldNode->toValue() === $newNode->toValue()) {
			return;

		} elseif ($oldNode instanceof ArrayNode && $newNode instanceof ArrayNode) {
			$newNode->indentation = $oldNode->indentation;
			if ($oldNode->indentation !== null) {
				$this->replaceArrayItems($oldNode->items, $newNode->items, $indentation . $oldNode->indentation);
				return;
			}
		}

		$newStr = $newNode instanceof ArrayNode && $newNode->indentation !== null ? "\n" : '';
		$newStr .= $newNode->toString();
		$newStr = rtrim($newStr);
		$newStr = self::indent1($newStr, $indentation . "\t");

		$this->replaceWith($newStr, $oldNode->startPos, $oldNode->endPos);
	}


	/**
	 * @param  ArrayItemNode[]  $oldItems
	 * @param  ArrayItemNode[]  $newItems
	 */
	private function replaceArrayItems(array $oldItems, array $newItems, string $indentation): void
	{
		$differ = new Differ(function (Node $old, Node $new) { return $old === ($new->data['originalNode'] ?? null); });
		$steps = $differ->diff($oldItems, $newItems);
		$newPos = $this->tokens->skipLeft($oldItems[0]->startPos, Token::WHITESPACE);

		foreach ($steps as [$type, $oldItem, $newItem]) {
			if ($type === $differ::REMOVE) {
				$startPos = $this->tokens->skipLeft($oldItem->startPos, Token::WHITESPACE);
				$endPos = $this->tokens->skipRight($oldItem->endPos, Token::WHITESPACE, Token::COMMENT);
				$endPos = $this->tokens->skipRight($endPos, Token::NEWLINE);
				$this->replaceWith('', $startPos, $endPos);

			} elseif ($type === $differ::KEEP) {
				$this->replaceNode($oldItem->value, $newItem->value, $indentation);
				$newPos = $this->tokens->skipRight($oldItem->value->endPos, Token::WHITESPACE, Token::COMMENT);
				$newPos = $this->tokens->skipRight($newPos, Token::NEWLINE); // jen jednou!
				$newPos++;

			} elseif ($type === $differ::ADD) {
				$newStr = Node\ArrayItemNode::itemsToBlockString([$newItem], $indentation);
				@$this->appends[$newPos] .= $indentation . $newStr;
			}
		}
	}


	private function replaceWith(string $new, int $startPos, int $endPos): void
	{
		for ($i = $startPos; $i <= $endPos; $i++) {
			$this->replacements[$i] = $this->replacements[$i] ?? '';
		}
		$this->replacements[$startPos] .= $new;
	}


	private static function indent1(string $s, string $indentation = "\t"): string
	{
		return str_replace("\n", "\n" . $indentation, $s);
	}
}
