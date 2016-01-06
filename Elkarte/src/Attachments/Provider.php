<?php

namespace Elkarte\Attachments;

use Elkarte\Elkarte\ProviderInterface;
use Pimple\Container;

class Provider implements ProviderInterface
{
	public function register(Container $elk)
	{
		$this->controllers($elk);

		$elk['attachments.file_manager'] = function ($elk) {
			return new Attachments();
		};

		$elk['attachments.context'] = function ($elk) {
			return new AttachmentContext;
		};
	}

	public function boot(Container $elk)
	{
		// Register attachment context handler
	}

	protected function controllers(Container $elk)
	{
		$elk['attachments.controller'] = function ($elk) {
			return new AttachmentController();
		};

		$elk['attachments.manage_controller'] = function ($elk) {
			return new ManageAttachmentsController();
		};

		$elk['attachment.moderate_controller'] = function ($elk) {
			return new ModerateAttachmentsController();
		};
	}

	protected function actions()
	{

	}
}