<?php

include("keys.php"); //include keys to lichess API
include("config.php"); //Include settings

//include functions
include("functions/global.php");
include("functions/moveTimes.php");
include("functions/blur.php");
include("functions/computerAnalysis.php");
include("functions/ratingIncrease.php");
include("functions/knownEngineIP.php");

function cheatIndex ( $username, $forceDeep = FALSE, $token = NULL, $target = "http://en.lichess.org/api/" ) {
	//Input: A players name
	//Output: A players cheat index

	global $SAMPLE_SIZE, $lichessApiToken;
	global $POINTS_TOTAL, $DEEP_SEARCH_THRESHOLD, $DEEP_SAMPLE_SIZE, $DEEP_SELECTION_SIZE, $DEEP_MOVE_THRESHOLD;
	global $REPORT_THRESHOLD, $MARK_THRESHOLD;

	if( $token == NULL ){
		$token = $lichessApiToken;
	}

	$gameReq = $target."game?username=$username&rated=1&nb=$SAMPLE_SIZE&token=$token"; //api request for player game data
	$playerReq = $target."user/$username?token=$lichessApiToken"; //api request for player information

	if ( ( $gameJson = file_get_contents( $gameReq ) ) != FALSE
		 && ( $playerJson = file_get_contents( $playerReq ) ) != FALSE ){
		/*
		$gameJson = file_get_contents( $gameReq ); //req game data
		$playerJson = file_get_contents( $playerReq ); //req player data
		*/

		$games = json_decode( $gameJson, TRUE )['list']; //decode game data
		$player = json_decode( $playerJson, TRUE ); //decode player data

		$deepIndex = 0;

		if ( !empty( $games ) && !empty( $player ) ){
			$action = "NOTHING";
			$reportDescription = "";
			$points = array();
			$reportGames = array();
			//-----Game Functions-------
			$points['SD'] = SDpoints( $games, $username );
			$points['BL'] = BLpoints( $games, $username );
			$points['CA'] = CApoints( $games, $username );

			//-----Player Functions-----
			$points['RI'] = RIpoints( $player['progress'] );
			$points['IP'] = IPpoints( $player['knownEnginesSharingIp'] );

			arsort( $points );

			$availableAlloc = 100;
			$cheatIndex = 0;

			foreach ($points as $key => $value) {
				if( $availableAlloc - $POINTS_TOTAL[$key] >= 0 ) {
					$availableAlloc -= $POINTS_TOTAL[$key];
					$cheatIndex += $value;
				}
			}

			if ( $cheatIndex >= $DEEP_SEARCH_THRESHOLD || $forceDeep == TRUE ) {
				if ( $cheatIndex >= $REPORT_THRESHOLD ) {
					$action = "REPORT";
				}

				$gameReq = $target."game?username=$username&rated=1&nb=$DEEP_SAMPLE_SIZE&token=$token";

				$games = json_decode( file_get_contents( $gameReq ), TRUE )['list'];
				$gameIndexes = array();
				//Process each of the games individually with individual indexes.
				foreach ( $games as $key => $unused ) {
					//this is a bit of a hack so I don't have to make a new set of functions.
					$game = array();
					$game[] = $games[$key];
					$deepPoints['SD'] = SDpoints( $game, $username, $DEEP_MOVE_THRESHOLD );
					$deepPoints['BL'] = BLpoints( $game, $username, $DEEP_MOVE_THRESHOLD );
					$deepPoints['CA'] = CApoints( $game, $username, $DEEP_MOVE_THRESHOLD );

					arsort( $deepPoints );

					$availableAlloc = 100;
					$gameIndex = 0;
					$summary = ""; //summary to be used in report.

					foreach ($deepPoints as $key => $value) {
						if( $availableAlloc - $POINTS_TOTAL[$key] >= 0 ) {
							$availableAlloc -= $POINTS_TOTAL[$key];
							$gameIndex += $value;

							if( $key == 'SD' ) {
								$summary .= "Consistent Move Times: $value, ";

							} else if ( $key == 'BL' ) {
								$summary .= "High Blur Rate: $value, ";

							} else if ( $key == 'CA' ) {
								$summary .= "Low Error Rate: $value, ";

							}
						}
					}
					$summaries[] = $summary.$games[$key]['url'];
					$gameIndexes[] = $gameIndex;
				}

				array_multisort( $gameIndexes, SORT_DESC, SORT_NUMERIC, $games, $summaries );
				//var_dump($gameIndexes);
				//var_dump($games);

				$returnedSampleSize = count( $games );
				$y = 0;

				//Calculate mean

				for($x = 0; $x < $DEEP_SELECTION_SIZE && $x < $returnedSampleSize; $x++ ){
					//printf( "%2.2f URL: %s\n", $gameIndexes[$x], $games[$x]['url'] );
					$reportDescription .= $summaries[$x].$games[$x]['url']."\n";
					$reportGames[] = array( "url" => $games[$x]['url'], "index" => floor( $gameIndexes[$x] ) );
					$sum += $gameIndexes[$x];
					$y++;
				}
				$deepIndex = $sum / $y;
				if ( $deepIndex >= $MARK_THRESHOLD ) {
					$action = "MARK";
				}
			}



			//-----Report Outputs-------
			$outputArray = array(
				"userId" => $username,
				"cheatIndex" => floor($cheatIndex),
				"deepIndex" => floor($deepIndex),
				"action" => $action,
				"reportDescription" => $reportDescription,
				"games" => $reportGames,
				"moveTime" => floor($points['SD']),
				"blur" => floor($points['BL']),
				"computerAnalysis" => floor($points['CA']),
				"progress" => floor($points['RI']),
				"knownEngineIP" => floor($points['IP']),
				"Error" => 0
				);
			$output = json_encode($outputArray);
			/*
			$format = '{"userId":"%s","cheatIndex":%3.2f,"deepIndex":%3.2f,"moveTime":%3.2f,"blur":%3.2f,"computerAnalysis":%3.2f,"progress":%3.2f,"knownEngineIP":%3.2f,"Error":0}';
			$output = sprintf($format, $username, $cheatIndex, $deepIndex, 
				$points['SD'],
				$points['BL'],
				$points['CA'],
				$points['RI'],
				$points['IP']);
				*/


		}else{
			$output = '{"Error" : 2}';
		}
	} else {
		$output = '{"Error" : 1}';
	}
	return $output;
}

//When calling from command line. Argv[1] is the username, and Argv[2] is the
//optional token to access hidden information.

$output = "";

if( isset( $argv[1] ) && isset( $argv[2] ) && isset( $argv[3] ) && isset( $argv[4] ) ) {
	$output = cheatIndex( strtolower( $argv[1] ), $argv[2], $argv[3], $argv[4] );
} else if ( isset( $argv[1] ) && isset( $argv[2] ) && isset( $argv[3] ) ) {
	$output = cheatIndex( strtolower( $argv[1] ), $argv[2], $argv[3] );
} else if ( isset( $argv[1] ) && isset( $argv[2] ) ) {
	$output = cheatIndex( strtolower( $argv[1] ), $argv[2] );
} else if ( isset( $argv[1] ) ) {
	$output = cheatIndex( strtolower( $argv[1] ) );
}

echo $output;
