<?php

/*

                     DON'T BE A DICK PUBLIC LICENSE

                       Version 1, December 2009

    Copyright (C) 2009 Philip Sturgeon <email@philsturgeon.co.uk>

    Everyone is permitted to copy and distribute verbatim or modified
    copies of this license document, and changing it is allowed as long
    as the name is changed.

                     DON'T BE A DICK PUBLIC LICENSE
       TERMS AND CONDITIONS FOR COPYING, DISTRIBUTION AND MODIFICATION

     1. Do whatever you like with the original work, just don't be a dick.

        Being a dick includes - but is not limited to - the following instances:

    1a. Outright copyright infringement - Don't just copy this and change the name.
    1b. Selling the unmodified original with no work done what-so-ever, that's REALLY being a dick.
    1c. Modifying the original work to contain hidden harmful content. That would make you a PROPER dick.

     2. If you become rich through modifications, related works/services, or supporting the original work,
    share the love. Only a dick would make loads off this work and not buy the original works 
    creator(s) a pint.

     3. Code is provided with no warranty. Using somebody else's code and bitching when it goes wrong makes 
    you a DONKEY dick. Fix the problem yourself. A non-dick would submit the fix back.

*/


/**
 * Mailman
 * (c)2009-2010 Kyle Bragger <kyle@forrst.com>
 *
 * Started Nov 18, 2009 (based on code from late 2007)
 *
 * Note: Make sure to create MailgetterProcessed and MailgetterFailed folders
 *
 * @todo Document this!
 * @todo Get (and save) attachments
 * @todo Test the shit out of this
 */

class Mailgetter {
    public static $username;
    public static $password;
    public static $host     = 'imap.gmail.com';
    public static $port     = 993;
    public static $messages = array();
    
    private static $_conn;
    private static $pri_body_type = array(
        0 => 'text',
        1 => 'multipart',
        2 => 'message',
        3 => 'application',
        4 => 'audio',
        5 => 'image',
        6 => 'video',
        7 => 'other'
    );
    private static $xfer_encodings = array(
        0 => '7BIT',
        1 => '8BIT',
        2 => 'BINARY',
        3 => 'BASE64',
        4 => 'QUOTED-PRINTABLE',
        5 => 'OTHER'
    );
    
    public static function get_mail()
    {
        self::_connect();
        self::_get_mail_from_inbox();
        self::_disconnect();
    }
    
    private static function _connect()
    {
        if (self::$_conn) return;
        
        $host = self::$host;
        $port = self::$port;
        self::$_conn = imap_open("{{$host}:{$port}/imap/ssl/novalidate-cert}INBOX", self::$username, self::$password);
    }
    
    private static function _disconnect()
    {
        if (!self::$_conn) return;
        
        imap_expunge(self::$_conn);
        imap_close(self::$_conn);
        self::$_conn = null;
    }
    
    private static function _get_mail_from_inbox()
    {
        if (!self::$_conn) throw new Exception('No open IMAP connection');
        
        $mail = array();
        
        $msgcount = imap_num_msg(self::$_conn);
        if ($msgcount)
        {
            for ($i = 1; $i <= $msgcount; $i++)
            {
                $failed = false;
                
                $headers = imap_headerinfo(self::$_conn, $i);
                $struct  = imap_fetchstructure(self::$_conn, $i);
                
                // Handle the message
                if (!$failed)
                {
                    // Get type and encoding info
                    $mail[$i] = array(
                        'body_type' => self::$pri_body_type[$struct->type],
                        'encoding'  => self::$xfer_encodings[$struct->encoding]
                    );
                    
                    // Get part data for primary part
                    $pri_part = false;
                    foreach ($struct->parts as $_part)
                    {
                        if (self::$pri_body_type[$_part->type] == 'text' && strtolower($_part->subtype) == 'plain')
                        {
                            $pri_part = $_part;
                            break;
                        }
                    }
                    $mail[$i]['primary_part'] = $pri_part;

                    // Decode the body
                    if ($mail[$i]['body_type'] == 'text')
                    {
                        $mail[$i]['body'] = imap_body(self::$_conn, $i);
                    }
                    elseif ($mail[$i]['body_type'] == 'multipart')  
                    {
                        $mail[$i]['body'] = imap_fetchbody(self::$_conn, $i, "1"); // TODO is this correct?
                    }
                    else
                    {
                        $failed           = true;
                        $mail[$i]['body'] = false;
                    }
                    
                    if ($mail[$i]['body'] !== false)
                    {
                        switch (self::$xfer_encodings[$mail[$i]['primary_part']->encoding])
                        {
                            case '7BIT':
                            {
                                // FIXME
                                //$mail[$i]['body'] = imap_utf7_decode($mail[$i]['body']);
                            }
                            break;
                            
                            case '8BIT':
                            {
                                $mail[$i]['body'] = imap_8bit($mail[$i]['body']);
                            }
                            break;
                            
                            case 'BINARY':
                            {
                                $mail[$i]['body'] = imap_binary($mail[$i]['body']);
                            }
                            break;
                            
                            case 'BASE64':
                            {
                                $mail[$i]['body'] = imap_base64($mail[$i]['body']);
                            }
                            break;
                            
                            case 'QUOTED-PRINTABLE':
                            {
                                $mail[$i]['body'] = quoted_printable_decode($mail[$i]['body']);
                            }
                            break;
                        }
                    }
                        
                    // Convert the message body to UTF-8
                    $mail[$i]['body'] = trim(iconv($mail[$i]['primary_part']->parameters[0]->value, 'UTF-8', $mail[$i]['body']));
                    
                    // Get the rest of what we need from the msg
                    $mail[$i]['subject']    = trim($headers->subject);
                    $mail[$i]['timestamp']  = strtotime($headers->date);
                    $mail[$i]['from']       = $headers->from[0];
                    
                    // Decode the subject
                    $mail[$i]['subject'] = imap_mime_header_decode($mail[$i]['subject']);
                    if ($mail[$i]['subject'][0]->charset != 'default')
                    {
                        $mail[$i]['subject'] = iconv(
                            $mail[$i]['subject'][0]->charset,
                            'UTF-8',
                            $mail[$i]['subject'][0]->text
                        );
                    }
                    else
                    {
                        $mail[$i]['subject'] = $mail[$i]['subject'][0]->text;
                    }

                    // Move it to the archive
                    if (!$failed) imap_mail_move(self::$_conn, $i, 'MailgetterProcessed');
                }
                
                if ($failed)
                {
                    // Mark as failed
                    imap_mail_move(self::$_conn, $i, 'MailgetterFailed');
                }
            }
        }
        self::$messages = $mail;
    }
}

// Test
Mailgetter::$username = 'you@foo.com';
Mailgetter::$password = 'yourpass';
Mailgetter::get_mail();

print_r(Mailgetter::$messages);
