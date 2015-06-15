<?php
	if(!isset($_FILES) || empty($_FILES)){
?><!DOCTYPE html>
<html>
<head><title>WinSCP configuration file converter</title></head>
	<body>
		<h1>Convert your WinSCP site manager data to Filezilla format</h1>
		<form enctype="multipart/form-data" action="<?=$_SERVER['SCRIPT_NAME']?>" method="post">
			<input type="hidden" name="MAX_FILE_SIZE" value="1048576" />
			<label for="userfile">INI file : </label><input name="userfile" type="file" id="userfile" />
			<input type="submit" />
		</form>
	</body>
</html>
<?php 
		die;
	}
	
	function processStructure($aStructureToParse, $oRootElement, $oParentElement){
		if(is_array($aStructureToParse)){
			foreach ($aStructureToParse as $sFolderName => $aSubStructure){
				// site names are prefixed by "s|"
				if(preg_match('/^s\|/', $sFolderName) == 1){
					// this is a <Site>, only append it to the parent <Folder>
					$sSiteName = preg_replace('/^s\|/', '', $sFolderName);
					$oParentElement->appendChild($aSubStructure);
				} else{
					// this is a <Folder>, create the <Folder> element, and parse the substructure
					$oCurrentFolder = $oRootElement->createElement('Folder', $sFolderName);
					$oParentElement->appendChild($oCurrentFolder);
					processStructure($aSubStructure, $oRootElement, $oCurrentFolder);
				}
			}
		}
	}
	
	// open the file, filter out non session-related data
	$handle = fopen($_FILES['userfile']['tmp_name'], "r");
	$sINIFile = '';
	if ($handle) {
		$bKeepSection = true;
		while (($line = fgets($handle)) !== false) {
			if (preg_match('/^\[/', $line)){
				// new section ; by default do not keep the following lines except it is session data
				if(preg_match('/^\[Sessions\\\\/', $line)){
					$bKeepSection = true;
					$sINIFile .= $line;
				}else{
					$bKeepSection = false;
				}
			}else{
				// process the line read. If it is site data, keep it.
				if($bKeepSection){
					$sINIFile .= $line;
				}
			}
		}

		fclose($handle);
	}
	
	$aINIFile = parse_ini_string($sINIFile, true, INI_SCANNER_RAW);
	$aDirectoryStructure = array();

	$oDoc = new DomDocument("1.0", "UTF-8");
	$oRootNode = $oDoc->createElement('FileZilla3');
	$oDoc->appendChild($oRootNode);
	$oServers = $oDoc->createElement('Servers');
	$oRootNode->appendChild($oServers);
	$oDoc->preserveWhiteSpace = false;
    $oDoc->formatOutput = true;
    $oDoc->standalone = true;
	
	ksort($aINIFile);
	foreach ($aINIFile as $sSessionName => $aSessionConf){
		// 1 create structure from the session name
		$sSessionName = str_replace('Sessions\\','', $sSessionName);
		$aFoldersStructure = explode('/', $sSessionName);
		$aFoldersToCreate = array_splice($aFoldersStructure, 0, count($aFoldersStructure)-1);
		$sSessionLabel = urldecode($aFoldersStructure[count($aFoldersStructure) -1 ]);
		
		// 2 export conf, in the right structure
		$oServer = $oDoc->createElement('Server', $sSessionLabel);
		$oNodeToAdd = $oDoc->createElement('Host', $aSessionConf['HostName']);
		$oServer->appendChild($oNodeToAdd);
		
		$oNodeToAdd = $oDoc->createElement('Protocol', 1);
		$oServer->appendChild($oNodeToAdd);
		
		if(array_key_exists('UserName', $aSessionConf)){
			$sUserName = $aSessionConf['UserName'];
		}else{
			$sUserName = '';
		}
		if(array_key_exists('Password', $aSessionConf)){
			$sCurrentPassword = $aSessionConf['Password'];
		}else{
			$sCurrentPassword = '';
		}
		$oNodeToAdd = $oDoc->createElement('User', $sUserName);
		$oServer->appendChild($oNodeToAdd);
		
		$sPassword = base64_encode(exec('/usr/bin/java -jar ' .  __DIR__ . '/WinSCPPasswordDecrypt.jar "' . $aSessionConf['HostName'] . '" "' . $sUserName . '" "' . $sCurrentPassword . '"'));
		$oPass = $oDoc->createElement('Pass', $sPassword);
		$oPassEncoding = $oDoc->createAttribute('encoding');
		$oPassEncoding->value = 'base64';
		$oPass->appendChild($oPassEncoding);
		$oServer->appendChild($oPass);
		
		if(array_key_exists('LocalDirectory', $aSessionConf)){
			$oNodeToAdd = $oDoc->createElement('LocalDir', urldecode($aSessionConf['LocalDirectory']));
		}else{
			$oNodeToAdd = $oDoc->createElement('LocalDir', '');
		}
		$oServer->appendChild($oNodeToAdd);

		if(array_key_exists('RemoteDirectory', $aSessionConf)){
			$aRemoteDir = explode('/',urldecode($aSessionConf['RemoteDirectory']));
			$sProcessedRemoteDir = '1';
			foreach ($aRemoteDir as $sDirectory){
				$sProcessedRemoteDir .= ' ' . strlen($sDirectory);
				if($sDirectory != ''){
					$sProcessedRemoteDir .= ' ' . $sDirectory;
				}
			}
		}else{
			$sProcessedRemoteDir = '';
		}
		$oNodeToAdd = $oDoc->createElement('RemoteDir', $sProcessedRemoteDir);
		$oServer->appendChild($oNodeToAdd);
		$oNodeToAdd = $oDoc->createElement('Port', isset($aSessionConf['PortNumber']) ? $aSessionConf['PortNumber'] : 22);
		$oServer->appendChild($oNodeToAdd);
		$oNodeToAdd = $oDoc->createElement('Type', 0);
		$oServer->appendChild($oNodeToAdd);
		$oNodeToAdd = $oDoc->createElement('Logontype', 1);
		$oServer->appendChild($oNodeToAdd);
		$oNodeToAdd = $oDoc->createElement('TimezoneOffset', 0);
		$oServer->appendChild($oNodeToAdd);
		$oNodeToAdd = $oDoc->createElement('PasvMode', 'MODE_DEFAULT');
		$oServer->appendChild($oNodeToAdd);
		$oNodeToAdd = $oDoc->createElement('MaximumMultipleConnections', 0);
		$oServer->appendChild($oNodeToAdd);
		$oNodeToAdd = $oDoc->createElement('EncodingType', 'Auto');
		$oServer->appendChild($oNodeToAdd);
		$oNodeToAdd = $oDoc->createElement('BypassProxy', 0);
		$oServer->appendChild($oNodeToAdd);
		$oNodeToAdd = $oDoc->createElement('SyncBrowsing', 0);
		$oServer->appendChild($oNodeToAdd);
		$oNodeToAdd = $oDoc->createElement('Comments');
		$oServer->appendChild($oNodeToAdd);
		$oNodeToAdd = $oDoc->createElement('Name', $sSessionLabel);
		$oServer->appendChild($oNodeToAdd);
		
		// 3 from the last directory in the structure, "stack" directories on onto another
		$aTempStructure = Array();
		for($iCptElements = count($aFoldersToCreate) - 1; $iCptElements >= 0; $iCptElements --){
			if($iCptElements == count($aFoldersToCreate) - 1){
				$aTempStructure = Array (
					$aFoldersToCreate[$iCptElements] => Array(
						 's|' . $sSessionLabel => $oServer
					)
				);
			}else{
				$aTempStructure = Array ($aFoldersToCreate[$iCptElements] => $aTempStructure);
			}
		}

		// add the directory structure to the global directory structure
		$aDirectoryStructure = array_merge_recursive($aDirectoryStructure, $aTempStructure);
	}
	
	// 4 within $aDirectoryStructure, there is the folder structure, along with the XML nodes.
	// Now, remains to create <folder> nodes for each level of the array, then add the DOM element present in it.
	processStructure($aDirectoryStructure, $oDoc, $oServers);
	
	header("Cache-Control: public");
	header("Content-Description: File Transfer");
	header("Content-Disposition: attachment; filename=sitemanager.xml");
	header("Content-Type: application/octet-stream; "); 
	header("Content-Transfer-Encoding: binary");
	echo $oDoc->saveXML();
	
	unlink($_FILES['userfile']['tmp_name']);
?>
