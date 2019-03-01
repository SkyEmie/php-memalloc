<?php

/**
 *              ** MemAlloc **
 *
 * @since 29 January, 2019
 * @version 1.8 - 1 March, 2019
 * @author Sky' <@SkywalkerFR on twitter>
 *
 * Only compatible with unix systems
 */


/* Init memalloc */
 if (function_exists('shmop_open')) $memalloc_load = True;
 if (!isset($memalloc_max_memory))  $memalloc_max_memory = 50; /* 50Mo max */
 if (!isset($memalloc_autoclean))   $memalloc_autoclean = 80; /* when 80% of memalloc_max_memory is used */
 

/**
* memalloc_write funct.
*
* Writing in shared memory
*
* @param  string 	$stackname 	stackname
* @param  string 	$data 		content to share
* @return boolean
*
*/
function memalloc_write($stackname, $data) {

	if (memalloc_get_stackid($stackname) == False) {
		/* stack does not exist, gen */
		$stackid = memalloc_gen_stackid();
		
	} else {
		/* stack exist, get id to delete old data */
		$stackid = memalloc_get_stackid($stackname);
		$shmid = shmop_open($stackid, 'w', 0, 0);
		shmop_delete($shmid);
	}
	
	/* clean up */
	global $memalloc_max_memory, $memalloc_autoclean;
	if (round((memory_get_usage() + mb_strlen($data)) / 100000, 0) >= $memalloc_max_memory) {
		while (round((memory_get_usage() + mb_strlen($data)) / 100000, 0) >= $memalloc_max_memory * ($memalloc_autoclean / 100)) {
			memalloc_clean();
		}
	}

	/* write into stack */
	$shmid = shmop_open($stackid, 'c', 0777, mb_strlen($data));

	shmop_write($shmid, $data, 0);
	shmop_close($shmid);

	/* -- update index (1) table stack name => (id, timestamp) -- */
	/* if index exist : get & delete */
	if (@$shmid = shmop_open(1, 'w', 0, 0)) {
		$size  = shmop_size($shmid);
		$index = json_decode(shmop_read($shmid, 0, $size), True);
		shmop_delete($shmid);
		shmop_close($shmid);
	}

	$index[$stackname] = array($stackid, time());
	$index = json_encode($index);

	/* recreate */
	$shmid = shmop_open(1, 'c', 0777, mb_strlen($index));
	shmop_write($shmid, $index, 0);
	shmop_close($shmid);

	return(True);

}




/**
* memalloc_read funct.
*
* Reading in shared memory
*
* @param  string 	$stackname 	stackname
* @return boolean/data
*
*/
function memalloc_read($stackname) {
	if (memalloc_get_stackid($stackname) == False) {
		/* stack does not exist */
		return(False);
		
	} else {
		/* stack exist, get id to read data */
		$stackid = memalloc_get_stackid($stackname);

		$shmid = shmop_open($stackid, 'a', 0, 0);
		$size  = shmop_size($shmid);
		$data = shmop_read($shmid, 0, $size);
		shmop_close($shmid);

		return($data);
	}
}




/**
* memalloc_delete funct.
*
* Delete an existing stack in the shared memory
*
* @param  string 	$stackname 	stackname
* @return boolean
*
*/
function memalloc_delete($stackname) {
	if (memalloc_get_stackid($stackname) == False) {
		/* stack does not exist */
		return(False);
		
	} else {
		/* stack exist, get id to delete stack */
		$stackid = memalloc_get_stackid($stackname);

		$shmid = shmop_open($stackid, 'w', 0, 0);
		shmop_delete($shmid);
		shmop_close($shmid);

		/* -- update index (1) table stack name => (id, timestamp) -- */
		/* if index exist : get & delete */
		if (@$shmid = shmop_open(1, 'w', 0, 0)) {
			$size  = shmop_size($shmid);
			$index = json_decode(shmop_read($shmid, 0, $size), True);
			shmop_delete($shmid);
			shmop_close($shmid);
		}

		unset($index[$stackname]);
		$index = json_encode($index);

		/* recreate */
		$shmid = shmop_open(1, 'c', 0777, mb_strlen($index));
		shmop_write($shmid, $index, 0);
		shmop_close($shmid);

		return(True);
	}
}




/**
* memalloc_clean funct.
*
* Delete oldest stack to free memory
*
* @return boolean
*
*/
function memalloc_clean() {
	/* read index (1) table stack name */
	if (!@$shmid = shmop_open(1, 'a', 0, 0)) return(False);
	$size  = shmop_size($shmid);
	$index = json_decode(shmop_read($shmid, 0, $size), True);
	shmop_close($shmid);

	if (!is_array($index)) return(False);

	/* sort stack from the oldest to the most recent and keep 5 oldest */
	arsort($index);
	$index = array_slice($index, 4);

	/* delete 5 oldest stack */
	foreach ($index as $i_stackname => $i_stackcontent) {
		memalloc_delete($i_stackname);
	}

	/* when all is done return True */ 
	return(True);
}




/**
* memalloc_get_stackid funct.
*
* Correlation table (stackname => stackid)
*
* @param  string 	$stackname 	stackname
* @return boolean/stackid
*
*/
function memalloc_get_stackid($stackname) {
	/* read index (1) table stack name => (id, timestamp) */
	if (!@$shmid = shmop_open(1, 'a', 0, 0)) return(False);
	$size  = shmop_size($shmid);
	$index = json_decode(shmop_read($shmid, 0, $size), True);
	shmop_close($shmid);

	if (!is_array($index)) return(False);

	/* returns matching stackid */
	foreach ($index as $i_stackname => $i_stackcontent) {
		if ($stackname == $i_stackname) return($i_stackcontent[1]);
	}

	/* if nothing found return False */ 
	return(False);
}




/**
* memalloc_gen_stackid funct.
*
* Allocate an empty stackid
*
* @return stackid
*
*/
function memalloc_gen_stackid() {
	/* allocate empty stackid ($i = stackid) */
	for ($i=2; $i < 10000; $i++) { 
		if (empty(@shmop_open($i, 'a', 0, 0))) return($i);
	}
}




function memalloc_list_stack() {
	/* $i = stackid */
	for ($i=1; $i < 10000; $i++) { 
		@$shmid = shmop_open($i, 'a', 0, 0);

		if (!empty($shmid)) {
			$data = shmop_read($shmid, 0, shmop_size($shmid));
			shmop_close($shmid);
			echo '['.$i.'] ('.mb_strlen($data).') => '.$data.'<br>';
		}

	}
}




function memalloc_index() {
	@$shmid = shmop_open(1, 'a', 0, 0);

	if (!empty($shmid)) {
		$data = shmop_read($shmid, 0, shmop_size($shmid));
		shmop_close($shmid);
		echo '['.$i.'] ('.mb_strlen($data).') => '.$data.'<br>';
	}

}




function memalloc_purge() {
	for ($i=1; $i < 10000; $i++) { 
		$shmid = shmop_open($i, 'w', 0, 0);
		shmop_delete($shmid);
		shmop_close($shmid);
	}
}
