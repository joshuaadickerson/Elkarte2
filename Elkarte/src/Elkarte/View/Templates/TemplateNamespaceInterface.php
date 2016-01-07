<?php

namespace Elkarte\ElkArte\Theme\Templates;

use Elkarte\Elkarte;
use Elkarte\ElkArte\Theme\TemplatesContainer;

interface TemplateNamespaceInterface
{
	public function __construct(Elkarte $elk, Theme $theme, TemplatesContainer $templates);
}