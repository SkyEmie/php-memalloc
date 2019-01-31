<?php

require_once('lib/memalloc.php');

memalloc_write('var1', 'Foobar');
$data = memalloc_read('var1');
$data.= '123';

memalloc_write('var1', $data);
echo memalloc_read('var1');

memalloc_delete('var1');

?>