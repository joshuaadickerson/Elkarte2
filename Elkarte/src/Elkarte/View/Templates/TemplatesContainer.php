<?php

namespace Elkarte\ElkArte\Theme;

// Use pimple here?
class TemplatesContainer
{
	// use Pimple here?
	/** @var array the template classes */
	protected $namespaces = [];
	/** @var array the functions that in the namespaces */
	protected $templates = [];

	public function addNamespace($index, TemplateNamespaceInterface $namespace)
	{
		$this->namespaces[$index] = $namespace;
	}

	public function getNamespace($index)
	{

	}
}