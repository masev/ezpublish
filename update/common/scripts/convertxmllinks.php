#!/usr/bin/env php
<?php
//
// Created on: <01-Feb-2005 12:05:27 ks>
//
// Copyright (C) 1999-2005 eZ systems as. All rights reserved.
//
// This source file is part of the eZ publish (tm) Open Source Content
// Management System.
//
// This file may be distributed and/or modified under the terms of the
// "GNU General Public License" version 2 as published by the Free
// Software Foundation and appearing in the file LICENSE included in
// the packaging of this file.
//
// Licencees holding a valid "eZ publish professional licence" version 2
// may use this file in accordance with the "eZ publish professional licence"
// version 2 Agreement provided with the Software.
//
// This file is provided AS IS with NO WARRANTY OF ANY KIND, INCLUDING
// THE WARRANTY OF DESIGN, MERCHANTABILITY AND FITNESS FOR A PARTICULAR
// PURPOSE.
//
// The "eZ publish professional licence" version 2 is available at
// http://ez.no/ez_publish/licences/professional/ and in the file
// PROFESSIONAL_LICENCE included in the packaging of this file.
// For pricing of this licence please contact us via e-mail to licence@ez.no.
// Further contact information is available at http://ez.no/company/contact/.
//
// The "GNU General Public License" (GPL) is available at
// http://www.gnu.org/copyleft/gpl.html.
//
// Contact licence@ez.no if any conditions of this licencing isn't clear to
// you.
//

/*! \file convertxmllinks.php
  Database converter for eZ publish 3.6.
  Updates <link> tags in 'ezxmltext' type attributes: replaces 'id' attribute
  with 'url_id'.
  You should run this script before using database created with eZ publish
  version 3.5.* or lower.
*/

if( !file_exists( 'update/common/scripts' ) || !is_dir( 'update/common/scripts' ) )
{
    echo "Please run this script from the root document directory!\n";
    exit;
}

include_once( 'lib/ezutils/classes/ezcli.php' );
include_once( 'kernel/classes/ezscript.php' );

$cli =& eZCLI::instance();

$script =& eZScript::instance( array( 'description' => ( "\nDatabase converter for eZ publish 3.6.\n" .
                                                         "Updates <link> tags in 'ezxmltext' type attributes.\n" .
                                                         "Run this script before using database created with eZ publish version 3.5.* or lower." ),
                                      'use-session' => false,
                                      'use-modules' => false,
                                      'use-extensions' => false ) );

$script->startup();

$options = $script->getOptions( "[db-host:][db-user:][db-password:][db-database:][db-driver:]",
                                "",
                                array( 'db-host' => "Database host",
                                       'db-user' => "Database user",
                                       'db-password' => "Database password",
                                       'db-database' => "Database name",
                                       'db-driver' => "Database driver"
                                       ) );
$script->initialize();

$dbUser = $options['db-user'] ? $options['db-user'] : false;
$dbPassword = $options['db-password'] ? $options['db-password'] : false;
$dbHost = $options['db-host'] ? $options['db-host'] : false;
$dbName = $options['db-database'] ? $options['db-database'] : false;
$dbImpl = $options['db-driver'] ? $options['db-driver'] : false;
$siteAccess = $options['siteaccess'] ? $options['siteaccess'] : false;

if ( $siteAccess )
{
    changeSiteAccessSetting( $siteaccess, $siteAccess );
}

function changeSiteAccessSetting( &$siteaccess, $optionData )
{
    global $isQuiet;
    $cli =& eZCLI::instance();
    if ( file_exists( 'settings/siteaccess/' . $optionData ) )
    {
        $siteaccess = $optionData;
        if ( !$isQuiet )
            $cli->notice( "Using siteaccess $siteaccess for xml text field update" );
    }
    else
    {
        if ( !$isQuiet )
            $cli->notice( "Siteaccess $optionData does not exist, using default siteaccess" );
    }
}

$db =& eZDB::instance();
if ( !$db )
{
    $cli->notice( "Can't initialize database connection.\n" );
}

if ( $dbHost or $dbName or $dbUser or $dbImpl )
{
    $params = array();
    if ( $dbHost !== false )
        $params['server'] = $dbHost;
    if ( $dbUser !== false )
    {
        $params['user'] = $dbUser;
        $params['password'] = '';
    }
    if ( $dbPassword !== false )
        $params['password'] = $dbPassword;
    if ( $dbName !== false )
        $params['database'] = $dbName;
    $db =& eZDB::instance( $dbImpl, $params, true );
    if ( !$db )
    {
        $cli->notice( "Can't initialize database connection.\n" );
    }
    eZDB::setInstance( $db );
}

$xmlFieldsQuery = "SELECT id, version, contentobject_id, data_text
                   FROM ezcontentobject_attribute
                   WHERE data_type_string = 'ezxmltext'";

$xmlFieldsArray =& $db->arrayQuery( $xmlFieldsQuery );
$count = 0;

foreach( $xmlFieldsArray as $xmlField )
{
    $text = $xmlField['data_text'];
    $textLen = strlen ( $text );
    
    $isTextModified = false;
    $pos = 1;

    do
    {
        $literalTagBegin = strpos( $text, "<literal", $pos );
        if ( $literalTagBegin )
        {
            $literalTagEnd = strpos( $text, "</literal>", $literalTagBegin );
            if ( !$literalTagEnd )
                break;
            $pos = $literalTagEnd + 9;
        }
    
        $linkPos = strpos( $text, "<link", $pos );
        if ( $linkPos )
        {
            $linkTagBegin = $linkPos + 5;
            $linkTagEnd = strpos( $text, ">", $linkTagBegin );
            if ( !$linkTagEnd )
                break;
    
            $linkTag = substr( $text, $linkTagBegin, $linkTagEnd - $linkTagBegin );

            if ( strpos( $linkTag, " id=\"" ) !== false )
            {
                $linkTag = str_replace( " id=\"", " url_id=\"", $linkTag );
                $text = substr_replace( $text, $linkTag, $linkTagBegin, $linkTagEnd - $linkTagBegin );
    
                $isTextModified = true;
            }

            $pos = $linkTagEnd;
        }
        else
           break;

    } while ($pos < $textLen );

    if ( $isTextModified )
    {
        $sql = "UPDATE ezcontentobject_attribute SET data_text='" . $text .
           "' WHERE id=" . $xmlField['id'] . " AND version=" . $xmlField['version'];
        $db->query( $sql );

        if ( !$isQuiet )
            $cli->notice( "Attribute converted. Object ID: " . $xmlField['contentobject_id'] . ", version: ". $xmlField['version'] .
                          ", attribute ID :" . $xmlField['id'] );
        $count++;
    }
}
    if ( !$isQuiet )
    {
        if ( $count )
            $cli->notice( "Total: " . $count . " attribute(s) converted." );
        else
            $cli->notice( "No old-style <link> tags found." );
    }

$script->shutdown();

?>
