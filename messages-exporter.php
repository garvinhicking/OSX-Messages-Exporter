#!/usr/bin/env php
<?php
# Export Messages conversations to HTML files.
# Enhanced by Garvin Hicking @supergarv https://garv.in
# Based on https://github.com/PeterKaminski09/baskup, which was
# based on https://github.com/kyro38/MiscStuff/blob/master/OSXStuff/iMessageBackup.sh
#
# Basic Usage (see -h output for more):
# $ messages-exporter.php [-o|--output_directory output_directory]
#                         The path to the directory where the messages should be saved. Save files in the current directory by default.
#                         [-f|--flush]
#                         Flushes the existing backup DB.
#                         [-r|--rebuild]
#                         Rebuild the HTML files from the existing DB.

define( 'VERSION', 2 );

$options = getopt(
    "o:fhrd:t:",
    array(
        "output_directory:",
        "flush",
        "help",
        "rebuild",
        "database:",
        "date-start:",
        "date-stop:",
        "timezone:",
        "date-format:",
        "summary",
        "safe-filenames",
        "contact-csv:",
        "progress",
    )
);

if ( isset( $options['h'] ) || isset( $options['help'] ) ) {
	echo "Usage: messages-exporter.php [-o|--output_directory /path/to/output/directory] [-f|--flush] [-r|--rebuild] [-d|--database /path/to/chat/database]\n\n"
        . "    OPTIONS:\n"
        . "\n"

		. "    [-o|--output_directory]\n"
        . "      A path to the directory where the messages should be saved. Save files in the current directory by default.\n"
        . "\n"

		. "    [-f|--flush]\n"
		. "      Flushes the existing backup database, essentially starting over from scratch.\n"
		. "\n"

		. "    [-r|--rebuild]\n"
		. "      Rebuild the HTML files from the existing database.\n"
		. "\n"

		. "    [-d|--database /path/to/chat/database]\n"
        . "      You can specify an alternate database file if, for example, you're running this script on a backup of chat.db from another machine.\n"
		. "\n"

        . "    [--date-start YYYY-MM-DD]\n"
        . "      Optionally, specify the first date that should be queried from the Messages database.\n"
		. "\n"

        . "    [--date-stop YYYY-MM-DD]\n"
        . "      Optionally, specify the last date that should be queried from the Messages database.\n"
		. "\n"

        . "    [-t|--timezone \"America/Los_Angeles\"]\n"
        . "      Optionally, supply a timezone to use for any dates and times that are displayed. If none is supplied, times will be in UTC. For a list of valid timezones, see https://www.php.net/manual/en/timezones.php\n"
		. "\n"

        . "    [--date-format \"n/j/Y, g:i A\"]\n"
        . "      Optionally, supply a output dateformat to use. If none is supplied, a date will be shown like \"" . date("n/j/Y, g:i A", time()) . "\". For a list of valid timezones, see https://www.php.net/manual/en/datetime.format.php\n"
		. "\n"

        . "    [--summary]\n"
        . "      If set, the script will return a small summary with number of exported messages/chats and possible errors (missing attachments)\n"
		. "\n"

        . "    [--safe-filenames]\n"
        . "      If set, directory and filenames will only contain characters from A-Z, no special characters, no spaces.\n"
		. "\n"

        . "    [--contact-csv /path/to/contacts.csv]\n"
        . "      By default, contacts are matched by several lookup to system files, however a lookup may fail. In this case you can provide a CSV file with two columns \"Number,Name\" (Number can be an eMail address, too) that resolves a iMessage ID to a readable name. The CSV will take precedence over other address books, so you can use it to even override specific contact names that exist. Ensure the CSV file matches your local charset, use comma as separator, UNIX newlines and no enclosing quotes.\n"
		. "\n"

        . "    [--progress]\n"
        . "      When set, you will get a (simple) progress report while compiling data and output.\n"
		. "\n"

        . "";
	echo "\n";
	die();
}

if ( ! isset( $options['o'] ) && empty( $options['output_directory'] ) ) {
	$options['o'] = getcwd();
}
else if ( ! empty( $options['output_directory'] ) ) {
	$options['o'] = $options['output_directory'];
}

if ( ! empty( $options['database'] ) ) {
	$options['d'] = $options['database'];
}

if ( ! isset( $options['f'] ) && isset( $options['flush'] ) ) {
	$options['f'] = true;
}

if ( ! isset( $options['r'] ) && isset( $options['rebuild'] ) ) {
	$options['r'] = true;
}

if ( isset( $options['timezone'] ) ) {
	$options['t'] = $options['timezone'];
}

if ( isset( $options['o'] ) ) {
	$options['o'] = preg_replace( '/^~/', $_SERVER['HOME'], $options['o'] );
}

if ( isset( $options['d'] ) ) {
	$options['d'] = preg_replace( '/^~/', $_SERVER['HOME'], $options['d'] );
}

if ( ! isset( $options['date-format'] ) ) {
	$options['date-format'] = "n/j/Y, g:i A";
}

$customContactLookup = array();
if ( isset( $options['contact-csv'] ) ) {
	if ( ! file_exists( $options['contact-csv'] ) ) {
		die( "Error: The specified CSV file does not exist" );
	}
	$fp = fopen( $options['contact-csv'], 'rb');
	ini_set("auto_detect_line_endings", true);
	while ($line = fgetcsv( $fp, 0, ',' ) ) {
		if ( ! isset( $line[1] ) ) {
			die( "Error: The CSV format is invalid. Please check using comma as separator.\n" );
		}
		$customContactLookup[$line[0]] = $line[1];
	}

	if ( count( $customContactLookup ) == 0 ) {
		die( "Error: The specified CSV file does not seem to contain any data. Please check newlines and proper format.\n" );
	}

	if ( isset( $options['summary'] ) ) {
		echo count($customContactLookup) . " CSV contacts imported.\n";
	}
}
// Regular expression that may be used (when enabled) to transform directories and filenames to ASCII names.
// Anything NON-ASCII will be changed to the safe_filename_replacement (you can use "" to get shorter filenames; multi-char replacements at your own risk
$safe_filename_pattern = '@[^a-zA-Z0-9\.\-_]@';
$safe_filename_replacement = '-';

# Ensure a trailing slash on the output directory.
$options['o'] = rtrim( $options['o'], '/' ) . '/';

if ( ! empty( $options['t'] ) ) {
	try {
		new DateTimeZone( $options['t'] );
	} catch ( Exception $e ) {
		file_put_contents('php://stderr', "Invalid timezone identifier: " . $options['t'] . "\n" );
		die;
	}

	date_default_timezone_set( $options['t'] );

	$timezone = new DateTimeZone( $options['t'] );
	$time_right_now = new DateTime( 'now', $timezone );
	$timezone_offset = $timezone->getOffset( $time_right_now );
}
else {
	$timezone_offset = 0;
}

# Create the output directory if it doesn't exist.
if ( ! file_exists( $options['o'] ) ) {
	mkdir( $options['o'] );
}

$summary = array(
	'messages'      => 0,
	'chats'         => 0,
	'attachments'   => 0,
	'images'		=> 0,
	'videos' 		=> 0,
	'audio'			=> 0,
	'documents'     => 0,
	'warnings'      => array(
		'groupChats'                => array(),
		'emptyAttachmentFilenames'  => array(),
		'unknownDates'              => 0,
		'unknownMessages'           => 0,
		'filesNotFound'             => 0
	),
	'notices'      	=> array(
		'URLPreviews'               => 0
	),
	'start'         => microtime(true),
	'skipped'   => array(
		'videos'    => 0,
		'images'    => 0,
		'audio'     => 0,
		'documents' => 0,
        'total'     => 0
	)
);
$progress_total = 0;
$database_file = $options['o'] . 'messages-exporter.db';

if ( ! isset( $options['r'] ) ) {
	if ( isset( $options['f'] ) && file_exists( $database_file ) ) {
		unlink( $database_file );
	}
}

$temporary_db = $database_file;
$temp_db = new SQLite3( $temporary_db );
$temp_db->exec( "CREATE TABLE IF NOT EXISTS messages ( message_id INTEGER PRIMARY KEY, chat_title TEXT, is_attachment INT, attachment_mime_type TEXT, contact TEXT, is_from_me INT, timestamp TEXT, content TEXT, UNIQUE (chat_title, contact, timestamp, content, is_from_me) ON CONFLICT REPLACE )" );
$temp_db->exec( "CREATE INDEX IF NOT EXISTS chat_title_index ON messages (chat_title)" );
$temp_db->exec( "CREATE INDEX IF NOT EXISTS contact_index ON messages (contact)" );
$temp_db->exec( "CREATE INDEX IF NOT EXISTS timestamp_index ON messages (timestamp)" );

$temp_db->exec( "CREATE TABLE IF NOT EXISTS meta ( meta_id INTEGER PRIMARY KEY, meta_key TEXT, meta_value TEXT, UNIQUE (meta_key) ON CONFLICT REPLACE )" );

$previous_version = $temp_db->querySingle( "SELECT meta_value FROM meta WHERE meta_key='version'" );

if ( ! $previous_version ) {
	$previous_version = 1;
}

if ( $previous_version < 2 ) {
	// In version 2, we switched to timestamp-based attachment filenames. Update all existing attachments that are referenced in a message.
	$attachments_statement = $temp_db->prepare( "SELECT * FROM messages WHERE is_attachment=1" );
	$attachments = $attachments_statement->execute();

	while ( $attachment = $attachments->fetchArray() ) {
		$chat_title = $attachment['chat_title'];

		$old_attachment_filename = basename( $attachment['content'] );

		if ( ! $old_attachment_filename ) {
			continue;
		}

		$new_attachment_filename = date( 'Y-m-d H i s', strtotime( $attachment['timestamp'] ) ) . ' - ' . $old_attachment_filename;

		$chat_title_for_filesystem = get_chat_title_for_filesystem( $chat_title );
		$attachments_directory = get_attachments_directory( $chat_title_for_filesystem );

		if ( file_exists( $attachments_directory . $old_attachment_filename ) && ! file_exists( $attachments_directory . $new_attachment_filename ) ) {
			rename( $attachments_directory . $old_attachment_filename, $attachments_directory . $new_attachment_filename );
		}
	}
}

$version_statement = $temp_db->prepare( "INSERT INTO meta (meta_key, meta_value) VALUES ('version', :meta_value)" );
$version_statement->bindValue( ':meta_value', VERSION, SQLITE3_TEXT );
$version_statement->execute();

$updated_contacts_memo = array();

if ( ! isset( $options['r'] ) ) {
	$chat_db_path = $_SERVER['HOME'] . "/Library/Messages/chat.db";

	if ( isset( $options['d'] ) ) {
		$chat_db_path = $options['d'];
	}

	if ( ! file_exists( $chat_db_path ) ) {
		die( "Error: The file " . $chat_db_path . " does not exist.\n" );
	}

	if ( isset( $options['summary'] ) ) {
		echo "Using database: " . $chat_db_path . "\n";
	}

	$db = new SQLite3( $chat_db_path, SQLITE3_OPEN_READONLY );
	$chats = $db->query( "SELECT * FROM chat" );

    if ( isset( $options['progress'] ) ) {
        echo "Reading native iMessages...\n";
        $total_chats_query = $db->query( "SELECT COUNT(*) AS count FROM chat" );
        $total_chats_row = $total_chats_query->fetchArray( SQLITE3_ASSOC );
        $progress_total = $total_chats_row['count'];
    }

    $chat_index = 0;
	while ( $row = $chats->fetchArray( SQLITE3_ASSOC ) ) {
	    $chat_index++;

		if ( isset( $options['progress'] ) ) {
		    progress_output( $chat_index, $progress_total);
        }

		$guid = $row['guid'];
		$chat_id = $row['ROWID'];
		$contactArray = explode( ';', $guid );
		$contactNumber = array_pop( $contactArray );

		$participant_identifiers = array();
		$chat_participants_statement = $db->prepare(
			"SELECT id FROM handle WHERE ROWID IN (SELECT handle_id FROM chat_handle_join WHERE chat_id=:chat_id)"
		);
		$chat_participants_statement->bindValue( ':chat_id', $chat_id );
		$chat_participants = $chat_participants_statement->execute();

		while ( $participant = $chat_participants->fetchArray( SQLITE3_ASSOC ) ) {
			$participant_identifiers[] = get_contact_nicename( $participant['id'] );
		}

		sort( $participant_identifiers );
		$chat_title = implode( ", ", $participant_identifiers );

		if ( empty( $chat_title ) ) {
			$chat_title = $contactNumber;
		}

		$statement = $db->prepare(
			"SELECT
				*,
				message.ROWID,
				message.is_from_me,
				message.text,
				handle.id as contact,
				message.cache_has_attachments,
				datetime(message.date/1000000000 + strftime('%s', '2001-01-01 00:00:00'), 'unixepoch', 'localtime') AS date_from_nanoseconds,
				datetime(message.date + strftime('%s', '2001-01-01 00:00:00'), 'unixepoch', 'localtime') date_from_seconds
			FROM message LEFT JOIN handle ON message.handle_id=handle.ROWID
			WHERE message.ROWID IN (SELECT message_id FROM chat_message_join WHERE chat_id=:rowid)" );
		$statement->bindValue( ':rowid', $row['ROWID'] );

		$messages = $statement->execute();

		$message_index = 0;
		while ( $message = $messages->fetchArray( SQLITE3_ASSOC ) ) {
		    $message_index++;

			if ( isset( $options['progress'] ) ) {
				progress_output( $chat_index, $progress_total, $message_index);
			}

			if ( strpos( $chat_title, ', ' ) === false && ! isset( $updated_contacts_memo[ $message['contact'] ] ) ) {
				// Get all existing chat names for this contact ID.
				// If the contact name has changed, update it for old messages and update the folder and filenames.
				$stored_messages_statement = $temp_db->prepare( "SELECT chat_title FROM messages WHERE contact=:contact GROUP BY chat_title" );
				$stored_messages_statement->bindValue( ":contact", $message['contact'] );
				$stored_messages = $stored_messages_statement->execute();

				while ( $stored_message = $stored_messages->fetchArray( SQLITE3_ASSOC ) ) {
					if ( $stored_message['chat_title'] === $chat_title ) {
						continue;
					}

					if ( strpos( $stored_message['chat_title'], ', ' ) !== false ) {
						// Group chats are tricky. @todo
						$summary['warnings']['groupChats'][] = $stored_message['chat_title'];
						continue;
					}

					// If the contact name has changed, update it in old stored messages.
					$update_statement = $temp_db->prepare( "UPDATE messages SET chat_title=:new_chat_title WHERE contact=:contact AND chat_title=:old_chat_title" );
					$update_statement->bindValue( ":new_chat_title", $chat_title, SQLITE3_TEXT );
					$update_statement->bindValue( ":contact", $message['contact'] );
					$update_statement->bindValue( ":old_chat_title", $stored_message['chat_title'], SQLITE3_TEXT );
					$update_statement->execute();

					// Update the folder and filenames.

					// For the HTML, we can just delete it, since it gets regenerated.
					$old_html_file = get_html_file( get_chat_title_for_filesystem( $stored_message['chat_title'] ) );

					if ( file_exists( $old_html_file ) ) {
						unlink( $old_html_file );
					}

					// For the attachments directory, we need to create the new one and move everything from the old one.
					$old_attachments_directory = get_attachments_directory( get_chat_title_for_filesystem( $stored_message['chat_title'] ) );

					if ( file_exists( $old_attachments_directory ) ) {
						$new_attachments_directory = get_attachments_directory( get_chat_title_for_filesystem( $chat_title ) );

						if ( ! file_exists( $new_attachments_directory ) ) {
							mkdir( $new_attachments_directory );
						}

						shell_exec( "mv -n " . escapeshellarg( $old_attachments_directory ) . "* " . escapeshellarg( $new_attachments_directory ) );

						if ( empty( glob( $old_attachments_directory . "/*" ) ) ) {
							// If there were two files with the same filename, keep the one in the old directory.
							rmdir( $old_attachments_directory );
						}
					}
				}

				$updated_contacts_memo[ $message['contact'] ] = true;
			}

			// 0xfffc is the Object Replacement Character. Messages uses it as a placeholder for the image attachment, but we can strip it out because we process attachments separately.
			$message['text'] = trim( str_replace( '￼', '', $message['text'] ) );

			// Apple switched to storing a nanosecond value in the date field at some point.
			// Due to SQLite not being able to handle converting huge timestamp values to dates,
			// all dates would have been stored as some time on -1413-03-01, with no way to retrieve
			// the original date.
			//
			// What we can do is check if we've improperly stored the date for this message, and then
			// delete the bad record and insert a new record.  The "ON CONFLICT REPLACE" clause won't
			// do this automatically, because the timestamp is part of the unique index.
			//
			// Depending on the current environment, date_from_seconds might be right or date_from_nanoseconds might be right.
			// If date_from_seconds is right, then this DB shouldn't have been affected by the bug.
			// If date_from_nanoseconds is right, then we need to delete any records that used date_from_seconds.
			// Or, we can just delete any records that used date_from_seconds anyway, since it'll just be re-inserted in a moment.
			//
			// If dates are still being stored as seconds (and not nanoseconds), then date_from_nanoseconds will be very close to 978307200 (January 1, 2001).

			if ( strtotime( $message['date_from_nanoseconds'] ) - 978307200 < 1000 ) {
				$correct_date = $message['date_from_seconds'];
			}
			else {
				$correct_date = $message['date_from_nanoseconds'];
			}

			if ( ! empty( $options['date-start'] ) && $correct_date < $options['date-start'] . " 00:00:00" ) {
				continue;
			}

			if ( ! empty( $options['date-stop'] ) && $correct_date > $options['date-stop'] . " 23:59:59" ) {
				continue;
			}

			if ( ! empty( $message['text'] ) ) {
				if ( $correct_date != $message['date_from_seconds'] ) {
					$delete_old_date_statement = $temp_db->prepare(
						"DELETE FROM messages
						WHERE 
							chat_title=:chat_title AND "
							. ( $message['is_from_me'] ? " is_from_me=1 AND " : " contact=:contact AND is_from_me=0 AND " )
							. "timestamp=:timestamp AND
							content=:content" );

					$delete_old_date_statement->bindValue( ':chat_title', $chat_title, SQLITE3_TEXT );

					if ( ! $message['is_from_me'] ) {
						$delete_old_date_statement->bindValue( ':contact', $message['contact'], SQLITE3_TEXT );
					}

					$delete_old_date_statement->bindValue( ':timestamp', $message['date_from_seconds'], SQLITE3_TEXT );
					$delete_old_date_statement->bindValue( ':content', $message['text'], SQLITE3_TEXT );
					$delete_old_date_statement->execute();
				}

				$insert_statement = $temp_db->prepare( "INSERT INTO messages (chat_title, contact, is_from_me, timestamp, content) VALUES (:chat_title, :contact, :is_from_me, :timestamp, :content)" );
				$insert_statement->bindValue( ':chat_title', $chat_title, SQLITE3_TEXT );
				$insert_statement->bindValue( ':contact', $message['contact'], SQLITE3_TEXT );
				$insert_statement->bindValue( ':is_from_me', $message['is_from_me'] );
				$insert_statement->bindValue( ':timestamp', $correct_date, SQLITE3_TEXT );
				$insert_statement->bindValue( ':content', $message['text'], SQLITE3_TEXT );
				$insert_statement->execute();
			}

			// Handle any attachments.

			if ( isset( $message['balloon_bundle_id'] ) && 'com.apple.messages.URLBalloonProvider' === $message['balloon_bundle_id'] ) {
				// The attachment would just be a URL preview.
				$summary['notices']['URLPreviews']++;
				continue;
			}

			if ( $message['cache_has_attachments'] ) {
				$attachmentStatement = $db->prepare(
					"SELECT 
						attachment.filename,
						attachment.mime_type,
						*
					FROM message_attachment_join LEFT JOIN attachment ON message_attachment_join.attachment_id=attachment.ROWID
					WHERE message_attachment_join.message_id=:message_id"
				);
				$attachmentStatement->bindValue( ':message_id', $message['ROWID'] );

				$attachmentResults = $attachmentStatement->execute();

				while ( $attachmentResult = $attachmentResults->fetchArray( SQLITE3_ASSOC ) ) {
					if ( $correct_date != $message['date_from_seconds'] ) {
						// See the comment above for why we do this DELETE.
						$delete_old_date_statement = $temp_db->prepare(
							"DELETE FROM messages
							WHERE 
								chat_title=:chat_title AND "
								. ( $message['is_from_me'] ? " is_from_me=1 AND " : " contact=:contact AND is_from_me=0 AND " )
								. "timestamp=:timestamp AND
								content=:content" );

						$delete_old_date_statement->bindValue( ':chat_title', $chat_title, SQLITE3_TEXT );

						if ( ! $message['is_from_me'] ) {
							$delete_old_date_statement->bindValue( ':contact', $message['contact'], SQLITE3_TEXT );
						}

						$delete_old_date_statement->bindValue( ':timestamp', $message['date_from_seconds'], SQLITE3_TEXT );
						$delete_old_date_statement->bindValue( ':content', $attachmentResult['filename'], SQLITE3_TEXT );
						$delete_old_date_statement->execute();
					}

					if ( empty( $attachmentResult['filename'] ) ) {
						// Could be something like an Apple Pay request.
						// $attachmentResult['attribution_info'] has a hint: bplist00?TnameYbundle-idiApple?Pay_vcom.apple.messages.MSMessageExtensionBalloonPlugin:0000000000:com.apple.PassbookUIService.PeerPaymentMessage...
						// @todo
						$summary['warnings']['emptyAttachmentFilenames'][] = '#' . $attachmentResult['ROWID'] . ': ' . $attachmentResult['attribution_info'];
					}

					if ( ! empty( $options['d'] ) ) {
						// If we're running on a database that is not the default system DB, the attachments are likely not available,
						// and even if there's a filename match, it may not be the correct file.  Simply note that there was an attachment
						// that is now unavailable.
						$insert_statement = $temp_db->prepare( "INSERT INTO messages (chat_title, contact, is_from_me, timestamp, content) VALUES (:chat_title, :contact, :is_from_me, :timestamp, :content)" );
						$insert_statement->bindValue( ':chat_title', $chat_title, SQLITE3_TEXT );
						$insert_statement->bindValue( ':contact', $message['contact'], SQLITE3_TEXT );
						$insert_statement->bindValue( ':is_from_me', $message['is_from_me'] );
						$insert_statement->bindValue( ':timestamp', $correct_date, SQLITE3_TEXT );
						$insert_statement->bindValue( ':content', '[File unavailable: ' . $attachmentResult['filename'] . ']', SQLITE3_TEXT );
						$insert_statement->execute();
					}
					else {
						$insert_statement = $temp_db->prepare( "INSERT INTO messages (chat_title, contact, is_attachment, is_from_me, timestamp, content, attachment_mime_type) VALUES (:chat_title, :contact, 1, :is_from_me, :timestamp, :content, :attachment_mime_type)" );
						$insert_statement->bindValue( ':chat_title', $chat_title, SQLITE3_TEXT );
						$insert_statement->bindValue( ':contact', $message['contact'], SQLITE3_TEXT );
						$insert_statement->bindValue( ':is_from_me', $message['is_from_me'] );
						$insert_statement->bindValue( ':timestamp', $correct_date, SQLITE3_TEXT );
						$insert_statement->bindValue( ':attachment_mime_type', $attachmentResult['mime_type'], SQLITE3_TEXT );
						$insert_statement->bindValue( ':content', $attachmentResult['filename'], SQLITE3_TEXT );
						$insert_statement->execute();
					}
				}
			}
		}
	}
}

if ( isset( $options['progress'] ) ) {
	echo "\nNative iMessages prepared, compiling output...\n";
	$total_chats_query = $temp_db->query( "SELECT COUNT(*) AS count FROM messages" );
	$total_chats_row = $total_chats_query->fetchArray( SQLITE3_ASSOC );
	$progress_total = $total_chats_row['count'];
}

$contacts = $temp_db->query( "SELECT chat_title FROM messages GROUP BY chat_title ORDER BY chat_title ASC" );

if ( isset( $options['summary'] ) ) {
	echo "Using HTML output directory: " . $options['o'] . "\n";
}

$chat_index = array();
$progress_message_index = 0;

while ( $row = $contacts->fetchArray() ) {
	$chat_title = $row['chat_title'];

	$chat_title_for_filesystem = get_chat_title_for_filesystem( $chat_title );
	$html_file = get_html_file( $chat_title_for_filesystem );
	$attachments_directory = get_attachments_directory( $chat_title_for_filesystem );

	$conversation_participant_count = substr_count( $chat_title, "," ) + 2;

	if ( ! file_exists( $html_file ) ) {
		touch( $html_file );
	}

	$messages_statement = $temp_db->prepare( "SELECT * FROM messages WHERE chat_title=:chat_title ORDER BY timestamp ASC" );
	$messages_statement->bindValue( ':chat_title', $chat_title, SQLITE3_TEXT );
	$messages = $messages_statement->execute();

	$summary['chats']++;

	file_put_contents(
		$html_file,
		'<!doctype html>
<html>
	<head>
		<meta charset="UTF-8">
		<title>Conversation: ' . $chat_title . '</title>
		<style type="text/css">

		body { font-family: "Helvetica Neue", sans-serif; font-size: 10pt; }
		p { margin: 0; clear: both; }
		.timestamp { text-align: center; color: #8e8e93; font-variant: small-caps; font-weight: bold; font-size: 9pt; }
		.byline { text-align: left; color: #8e8e93; font-size: 9pt; padding-left: 1ex; padding-top: 1ex; margin-bottom: 2px; }
		img { max-width: 100%; }
		.message { text-align: left; color: black; border-radius: 8px; background-color: #e1e1e1; padding: 6px; display: inline-block; max-width: 75%; margin-bottom: 5px; float: left; }
		.message[data-from="self"] { text-align: right; background-color: #007aff; color: white; float: right;}

		</style>
	</head>
	<body>
' );

	$last_time = 0;
	$last_participant = null;

	$first_message = $last_message = null;
	$chat_stats = array(
	    'videos'    => 0,
        'images'    => 0,
        'audio'     => 0,
        'documents' => 0,
    );

	while ( $message = $messages->fetchArray() ) {
		$progress_message_index++;

		if ( isset( $options['progress'] ) ) {
			progress_output( $progress_message_index, $progress_total );
		}

		$summary['messages']++;
		$message['this_time'] = strtotime( $message['timestamp'] );

		if ( $message['this_time'] < 0 ) {
			// There was a bug present from when Apple started storing timestamps as nanoseconds instead of seconds, so the stored
			// timestamps were all from the year -1413. There's no way to fix it without re-importing the messages. Sorry.
			$message['this_time'] = 0;
			$message['timestamp'] = "Unknown Date";
			$summary['warnings']['unknownDates']++;
		}

		if ( $message['this_time'] - $last_time > ( 60 * 60 ) ) {
			$last_participant = null;

			file_put_contents(
				$html_file,
				"\t\t\t" . '<p class="timestamp" data-timestamp="' . $message['timestamp'] . '">' . date( $options['date-format'], $message['this_time'] + $timezone_offset ) . '</p><br />' . "\n",
				FILE_APPEND
			);
		}

		$last_time = $message['this_time'];

		if ( $conversation_participant_count > 2 && ! $message['is_from_me'] && $message['contact'] != $last_participant ) {
			$last_participant = $message['contact'];

			file_put_contents(
				$html_file,
				"\t\t\t" . '<p class="byline">' . htmlspecialchars( get_contact_nicename( $message['contact'] ) ) .'</p>' . "\n",
				FILE_APPEND
			);
		}

		if ( $message['is_attachment'] ) {
			if ( ! file_exists( $attachments_directory ) ) {
				mkdir( $attachments_directory );
			}

			if ( empty( $message['content'] ) ) {
				$html_embed = '[Unknown Message]';
				$summary['warnings']['unknownMessages']++;
			}
			else {
				// Give the attachment filename a date-based prefix to avoid filename collisions if this backup is ever migrated to another machine.
				if ( isset ( $GLOBALS['options']['safe-filenames'] ) ) {
					$basename = get_safe_filename( basename( $message['content'] ) );
					$attachment_filename = date( 'Y-m-d_H-i-s', strtotime( $message['timestamp'] ) ) . '-' . $basename;
				}
				else {
					$attachment_filename = date( 'Y-m-d H i s', strtotime( $message['timestamp'] ) ) . ' - ' . basename( $message['content'] );
				}

				$file_to_copy = preg_replace( '/^~/', $_SERVER['HOME'], $message['content'] );

				// If the file is no longer available and we didn't previously save it, show "File Not Found".
				if ( ! file_exists( $file_to_copy ) && ! file_exists( $attachments_directory . $attachment_filename ) ) {
					$html_embed = '[File Not Found: ' . $attachment_filename . ']';
					$summary['warnings']['filesNotFound']++;
				}
				else {
					if ( strpos( $message['content'], '.' ) !== false ) {
						list( $extension, $filename_base ) = array_map( 'strrev', explode( '.', strrev( basename( $message['content'] ) ), 2 ) );
					}
					else {
						$extension = null;
						$filename_base = basename( $message['content'] );
					}

					if (
					       // We previously saved the attachment but it's no longer available.
					       ( ! file_exists( $file_to_copy ) && file_exists( $attachments_directory . $attachment_filename ) )
					       ||
					       ( file_exists( $attachments_directory . $attachment_filename )
					         && sha1_file( $attachments_directory . $attachment_filename ) == sha1_file( $file_to_copy )
					         && filesize( $attachments_directory . $attachment_filename ) == filesize( $file_to_copy )
						   )
						) {
						// They're the same file. We've probably already run this script on the message that includes this file.
					}
					else {
						$suffix = 1;

						// If a file already exists where we want to save this attachment, add a suffix like -1, -2, -3, etc. until we get a unique filename.
						// But don't copy the file if the destination file is the same as the one we're copying.
                        // GH: Bugfix. Sadly there's a problem, because multiple identically named attachments (if an image got resized)
                        //     can exist for the SAME timestamp (if those files were sent at the same time).
                        //     So instead of renaming to a filename like "FullSizeRender-X.jpg" we now also use
                        //     "2021-03-03_22-00-30-FullSizeRender-X.jpg" instead. By keeping the timestamp, the uniqueness
                        //     will be applied on a next run. Before, a file would be renamed to FullSizeRender-X.jpg and then
                        //     everytime the rebuild was executed, a new -X would be created.
                        $performCopy = true;
						while ( file_exists( $attachments_directory . $attachment_filename ) ) {
							++$suffix;

							if ( isset ( $GLOBALS['options']['safe-filenames'] ) ) {
								$basename = get_safe_filename( $filename_base );
								$attachment_filename = date( 'Y-m-d_H-i-s', strtotime( $message['timestamp'] ) ) . '-' . $basename . '-' . $suffix;
							}
							else {
								$attachment_filename = date( 'Y-m-d H i s', strtotime( $message['timestamp'] ) ) . ' - ' . $filename_base . '-' . $suffix;
							}

							if ( $extension ) {
								$attachment_filename .= '.' . $extension;
							}

							// Now perform the same identity check
							if (
								file_exists( $attachments_directory . $attachment_filename )
									&& sha1_file( $attachments_directory . $attachment_filename ) == sha1_file( $file_to_copy )
									&& filesize( $attachments_directory . $attachment_filename ) == filesize( $file_to_copy )
							) {
								// They're the same file. We've probably already run this script on the message that includes this file.
								$performCopy = false;
								// Abort the while statement; the file exists, but is identical.
								break 1;
							}
						}

						if ($performCopy) {
							copy( $file_to_copy, $attachments_directory . $attachment_filename );
							$summary['attachments']++;
						}
					}

					$html_embed = '';

					if ( strpos( $message['attachment_mime_type'], 'image' ) === 0 ) {
						$html_embed = '<img src="' . $chat_title_for_filesystem . '/' . $attachment_filename . '" />';
						$summary['images']++;
						$chat_stats['images']++;
					}
					else {
						if ( strpos( $message['attachment_mime_type'], 'video' ) === 0 ) {
							$html_embed = '<video controls><source src="' . $chat_title_for_filesystem . '/' . $attachment_filename . '" type="' . $message['attachment_mime_type'] . '"></video><br />';
							$summary['videos']++;
							$chat_stats['videos']++;
						}
						else if ( strpos( $message['attachment_mime_type'], 'audio' ) === 0 ) {
							$html_embed = '<audio controls><source src="' . $chat_title_for_filesystem . '/' . $attachment_filename . '" type="' . $message['attachment_mime_type'] . '"></audio><br />';

							$summary['audio']++;
							$chat_stats['audio']++;
						}
						else {
							$summary['documents']++;
							$chat_stats['documents']++;
						}

						$html_embed .= '<a href="' . $chat_title_for_filesystem . '/' . $attachment_filename . '">' . htmlspecialchars( $attachment_filename ) . '</a>';
					}
				}
			}

			file_put_contents(
				$html_file,
				"\t\t\t" . '<p class="message" data-from="' . ( $message['is_from_me'] ? 'self' : $message['contact'] ) . '" data-timestamp="' . $message['timestamp'] . '" title="' . date( $options['date-format'], $message['this_time'] + $timezone_offset ) . '">' . $html_embed . '</p>',
				FILE_APPEND
			);
		}
		else {
			file_put_contents(
				$html_file,
				"\t\t\t" . '<p class="message" data-from="' . ( $message['is_from_me'] ? 'self' : $message['contact'] ) . '" data-timestamp="' . $message['timestamp'] . '" title="' . date( $options['date-format'], $message['this_time'] + $timezone_offset ) . '">' . nl2br( htmlspecialchars( trim( $message['content'] ) ) ) . '</p>',
				FILE_APPEND
			);
		}

		file_put_contents(
			$html_file,
			"<br />\n",
			FILE_APPEND
		);
	}

	file_put_contents( $html_file, "\t</body>\n</html>", FILE_APPEND );
if ( isset( $options['progress'] ) ) {
    echo "\nMessages created. Building TOC/index.\n";
}

if ( isset( $options['summary'] ) ) {
	echo "\nBuild finished. Summary:\n";
	echo "========================\n";
	echo "Number of messages: " . $summary['messages'] . "\n";
	echo "Number of chats: " . $summary['chats'] . "\n";
	echo "Number of attachments: " . $summary['attachments'] . " copied (total: " . $summary['images'] . " images, " . $summary['videos'] . " videos, " . $summary['audio'] . " audios, " . $summary['documents'] . " other)\n";
	echo "Number of skipped attachments: " . $summary['skipped']['total'] . " (total: " . $summary['skipped']['images'] . " images, " . $summary['skipped']['videos'] . " videos, " . $summary['skipped']['audio'] . " audios, " . $summary['skipped']['documents'] . " other)\n";
	echo "\n";
	echo "Notices:\n";
	echo "========\n";
	echo "Number of skipped URLPreview messages: " . $summary['notices']['URLPreviews'] . "\n";
	echo "\n";
	echo "Warnings:\n";
	echo "=========\n";
	if ( count( $summary['warnings']['groupChats'] ) > 0) {
		echo count( $summary['warnings']['groupChats'] ) . " GroupChats with multiple recipients diverted:\n";
		foreach( $summary['warnings']['groupChats'] AS $groupchatNumber => $groupChat ) {
			echo " * " . $groupChat . "\n";
		}
	}

	if ( count( $summary['warnings']['emptyAttachmentFilenames'] ) > 0) {
		echo count( $summary['warnings']['emptyAttachmentFilenames'] ) . " missing attachment filenames:\n";
		foreach( $summary['warnings']['emptyAttachmentFilenames'] AS $attachmentNumber => $attachmentFile ) {
			echo " * " . $attachmentFile . "\n";
		}
	}

	if ( $summary['warnings']['unknownDates'] > 0 ) {
		echo "Unknown Dates: " . $summary['warnings']['unknownDates'] . "\n";
	}

	if ( $summary['warnings']['unknownMessages'] > 0 ) {
		echo "Unknown/Empty Messages: " . $summary['warnings']['unknownMessages'] . "\n";
	}

	if ( $summary['warnings']['filesNotFound'] > 0 ) {
		echo "Files not found: " . $summary['warnings']['filesNotFound'] . "\n";
	}

	$timeTaken = microtime(true) - $summary['start'];
	echo "Finished in " . $timeTaken . " seconds.\n";
}

function get_contact_nicename( $contact_notnice_name ) {
	static $contact_nicename_map = array();

	if ( ! $contact_notnice_name ) {
		return $contact_notnice_name;
	}

	if ( isset( $contact_nicename_map[ $contact_notnice_name ] ) ) {
		return $contact_nicename_map[ $contact_notnice_name ];
	}

	if ( isset( $GLOBALS['customContactLookup'][$contact_notnice_name] ) ) {
		return $GLOBALS['customContactLookup'][$contact_notnice_name];
	}

	$contact_nicename_map[ $contact_notnice_name ] = $contact_notnice_name;

	// These are SQLite files that are synced with iCloud, I think.
	$possible_address_book_db_files = glob( $_SERVER['HOME'] . "/Library/Application Support/AddressBook/Sources/*/AddressBook-v22.abcddb" );

	// But check the local contacts DB first.
	array_unshift( $possible_address_book_db_files, $_SERVER['HOME'] . "/Library/Application Support/AddressBook/AddressBook-v22.abcddb" );

	foreach ( $possible_address_book_db_files as $address_book_db_file ) {
		if ( ! file_exists( $address_book_db_file ) ) {
			echo $address_book_db_file . " does not exist.\n";
			continue;
		}

		$contacts_db = new SQLite3( $address_book_db_file, SQLITE3_OPEN_READONLY );

		if ( strpos( $contact_notnice_name, '@' ) !== false ) {
			// Assume an email address.
			$nameStatement = $contacts_db->prepare(
				"SELECT
					ZABCDRECORD.ZFIRSTNAME,
					ZABCDRECORD.ZLASTNAME
				FROM ZABCDEMAILADDRESS
					LEFT JOIN ZABCDRECORD ON ZABCDEMAILADDRESS.ZOWNER=ZABCDRECORD.Z_PK
				WHERE
					ZABCDEMAILADDRESS.ZADDRESS=:address"
			);

			$nameStatement->bindValue( ':address', $contact_notnice_name );
			$nameResults = $nameStatement->execute();

			while ( $nameResult = $nameResults->fetchArray( SQLITE3_ASSOC ) ) {
				$name = trim( $nameResult['ZFIRSTNAME'] . ' ' . $nameResult['ZLASTNAME'] );

				if ( $name ) {
					$contact_nicename_map[ $contact_notnice_name ] = $name;
					break 2;
				}
			}
		}
		else {
			// Assume a phone number.
			$forms = array();
			$forms[] = $contact_notnice_name;
			$forms[] = preg_replace( '/[^0-9]/', '', $contact_notnice_name );
			$forms[] = preg_replace( '/[^0-9]/', '', preg_replace( '/^\+1/', '', $contact_notnice_name ) );

			$forms = array_unique( $forms );

			$phoneNumberStatement = $contacts_db->prepare( "SELECT ZOWNER, ZFULLNUMBER FROM ZABCDPHONENUMBER" );
			$phoneNumberResults = $phoneNumberStatement->execute();

			while ( $phoneNumberResult = $phoneNumberResults->fetchArray( SQLITE3_ASSOC ) ) {
				if (
					in_array( $phoneNumberResult['ZFULLNUMBER'], $forms )
					|| in_array( preg_replace( '/[^0-9]/', '', $phoneNumberResult['ZFULLNUMBER'] ), $forms )
					|| in_array( preg_replace( '/^\+1/', '', preg_replace( '/[^0-9]/', '', $phoneNumberResult['ZFULLNUMBER'] ) ), $forms )
					) {
					$nameStatement = $contacts_db->prepare(
						"SELECT ZABCDRECORD.ZFIRSTNAME, ZABCDRECORD.ZLASTNAME, ZABCDRECORD.ZORGANIZATION FROM ZABCDRECORD WHERE Z_PK = :zowner"
					);
					$nameStatement->bindValue( ':zowner', $phoneNumberResult['ZOWNER'] );
					$nameResults = $nameStatement->execute();

					while ( $nameResult = $nameResults->fetchArray( SQLITE3_ASSOC ) ) {
						$name = trim( $nameResult['ZFIRSTNAME'] . ' ' . $nameResult['ZLASTNAME'] );

						if ( $nameResult['ZORGANIZATION'] ) {
							if ( ! $name ) {
								$name = $nameResult['ZORGANIZATION'];
							}
							else {
								$name .= ' (' . $nameResult['ZORGANIZATION'] . ')';
							}
						}

						if ( $name ) {
							$contact_nicename_map[ $contact_notnice_name ] = $name;
							break 3;
						}
					}
				}
			}
		}
	}

	return $contact_nicename_map[ $contact_notnice_name ];
}

function get_safe_filename( $file ) {
    // This is kind of a hack to try to remove some usual umlauts without requiring specific locales
    // or iconv extension with ASCII//TRANSLIT or a static map.
    // @todo Don't know how this might work with chinese or other character sets
    if ( strpos($file = htmlentities($file, ENT_QUOTES, 'UTF-8'), '&') !== false ) {
        $file = html_entity_decode(preg_replace('~&([a-z]{1,2})(?:acute|cedil|circ|grave|lig|orn|ring|slash|tilde|uml);~i', '$1', $file), ENT_QUOTES, 'UTF-8');
    }

	return preg_replace( $GLOBALS['safe_filename_pattern'], $GLOBALS['safe_filename_replacement'], $file );
}

function get_chat_title_for_filesystem( $chat_title ) {
	$chat_title_for_filesystem = $chat_title;

	// Mac OSX has a 255-char filename limit, so if the number of contacts in a chat
	// would push the filenames past 255 chars, truncate the filename and add an identifier
	// to ensure that another chat with the same initial list of contacts doesn't overlap
	// with it.

	// Colon and slash are prohibited in filenames on Mac.
	$chat_title_for_filesystem = str_replace( array( ":", "/" ), "-", $chat_title_for_filesystem );

	// Check for valid filenames and remove anything that is not ASCII (helpful for i.e. Dropbox syncing where many filenames will clash with
	// different OSes
	if ( isset ( $GLOBALS['options']['safe-filenames'] ) ) {
		$chat_title_for_filesystem = get_safe_filename( $chat_title_for_filesystem );
		$separator = $GLOBALS['safe_filename_replacement'];
	}
	else {
		$separator = ' ';
	}

	if ( strlen( $chat_title_for_filesystem . ".html" ) > 255 ) {
		$unique_chat_hash = "{" . md5( $chat_title ) . "}";

		// Shorten the filename until there's enough room for the identifying hash and a space.
		while ( strlen( $chat_title_for_filesystem . ".html" ) > 255 - 1 - strlen( $unique_chat_hash ) ) {
			$chat_title_for_filesystem = explode( $separator, $chat_title_for_filesystem );
			array_pop( $chat_title_for_filesystem );
			$chat_title_for_filesystem = join( $separator, $chat_title_for_filesystem );
		}

		$chat_title_for_filesystem .= $separator . $unique_chat_hash;
	}

	return $chat_title_for_filesystem;
}

function get_html_file( $chat_title_for_filesystem ) {
	global $options;

	return $options['o'] . $chat_title_for_filesystem . '.html';
}

function get_attachments_directory( $chat_title_for_filesystem ) {
	global $options;

	return $options['o'] . $chat_title_for_filesystem . '/';
}

function progress_output($done, $total, $extra = '') {
    $extraOut = '';
    if ($extra !== '') {
        $extraOut .= ' [' . $extra . ']';
    }
	$write = "\033[0G\033[2K " . $done . "/" . $total . $extraOut;
	fwrite(STDERR, $write);
}
