<?php if ( !isset($this) ) die('Direct access to this file is not allowed') ?>

CKEDITOR.config.baseHref    = 'http://<?php echo $_SERVER['SERVER_NAME'] . $this->rootPath ?>';
CKEDITOR.config.height      = '400';
CKEDITOR.config.bodyId      = 'content';
CKEDITOR.config.contentsCss = '<?php echo $this->route('css/global.css/grid.css/ckeditor.css') ?>';
CKEDITOR.config.toolbar     = [
	['Format'],
	['Bold', 'Italic', 'Strike'],
	['NumberedList', 'BulletedList'],
	['Link', 'Unlink'],
	['Image', 'Flash', 'Table', 'SpecialChar'],
	['PasteText', 'PasteFromWord'],
	['RemoveFormat'],
	['Source']
	];

CKEDITOR.config.filebrowserBrowseUrl    = '<?php echo $this->route('admin/files?callback=fileBrowserCallback') ?>';
CKEDITOR.config.filebrowserWindowWidth  = '1000';
CKEDITOR.config.filebrowserWindowHeight = '75%';

function fileBrowserCallback(url) {
	CKEDITOR.tools.callFunction(1, url);
}
