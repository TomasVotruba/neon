<?php

/**
 * This file is part of the Nette Framework (https://nette.org)
 * Copyright (c) 2004 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace Nette\Neon;


/** @internal */
abstract class Node
{
	/** @var ?int */
	public $startPos;

	/** @var ?int */
	public $endPos;

	/** @var array */
	public $data = [];


	/**
	 * @param  callable(self): mixed|null  $evaluator
	 * @return mixed
	 */
	abstract public function toValue(callable $evaluator = null);


	/** @param  callable(self): string|null  $serializer */
	abstract public function toString(callable $serializer = null): string;


	/** @return self[] */
	public function getSubNodes(): array
	{
		return [];
	}
}
