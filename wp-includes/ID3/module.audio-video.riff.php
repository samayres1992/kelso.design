<?php
/////////////////////////////////////////////////////////////////
/// getID3() by James Heinrich <info@getid3.org>               //
//  available at http://getid3.sourceforge.net                 //
//            or http://www.getid3.org                         //
//          also https://github.com/JamesHeinrich/getID3       //
/////////////////////////////////////////////////////////////////
// See readme.txt for more details                             //
/////////////////////////////////////////////////////////////////
//                                                             //
// module.audio-video.riff.php                                 //
// module for analyzing RIFF files                             //
// multiple formats supported by this module:                  //
//    Wave, AVI, AIFF/AIFC, (MP3,AC3)/RIFF, Wavpack v3, 8SVX   //
// dependencies: module.audio.mp3.php                          //
//               module.audio.ac3.php                          //
//               module.audio.dts.php                          //
//                                                            ///
/////////////////////////////////////////////////////////////////

/**
* @todo Parse AC-3/DTS audio inside WAVE correctly
* @todo Rewrite RIFF parser totally
*/

getid3_lib::IncludeDependency(GETID3_INCLUDEPATH.'module.audio.mp3.php', __FILE__, true);
getid3_lib::IncludeDependency(GETID3_INCLUDEPATH.'module.audio.ac3.php', __FILE__, true);
getid3_lib::IncludeDependency(GETID3_INCLUDEPATH.'module.audio.dts.php', __FILE__, true);

class getid3_riff extends getid3_handler {

	protected $container = 'riff'; // default

	public function Analyze() {
		$info = &$this->getid3->info;

		// initialize these values to an empty array, otherwise they default to NULL
		// and you can't append array values to a NULL value
		$info['riff'] = array('raw'=>array());

		// Shortcuts
		$thisfile_riff             = &$info['riff'];
		$thisfile_riff_raw         = &$thisfile_riff['raw'];
		$thisfile_audio            = &$info['audio'];
		$thisfile_video            = &$info['video'];
		$thisfile_audio_dataformat = &$thisfile_audio['dataformat'];
		$thisfile_riff_audio       = &$thisfile_riff['audio'];
		$thisfile_riff_video       = &$thisfile_riff['video'];

		$Original['avdataoffset'] = $info['avdataoffset'];
		$Original['avdataend']    = $info['avdataend'];

		$this->fseek($info['avdataoffset']);
		$RIFFheader = $this->fread(12);
		$offset = $this->ftell();
		$RIFFtype    = substr($RIFFheader, 0, 4);
		$RIFFsize    = substr($RIFFheader, 4, 4);
		$RIFFsubtype = substr($RIFFheader, 8, 4);

		switch ($RIFFtype) {

			case 'FORM':  // AIFF, AIFC
				//$info['fileformat']   = 'aiff';
				$this->container = 'aiff';
				$thisfile_riff['header_size'] = $this->EitherEndian2Int($RIFFsize);
				$thisfile_riff[$RIFFsubtype]  = $this->ParseRIFF($offset, ($offset + $thisfile_riff['header_size'] - 4));
				break;

			case 'RIFF':  // AVI, WAV, etc
			case 'SDSS':  // SDSS is identical to RIFF, just renamed. Used by SmartSound QuickTracks (www.smartsound.com)
			case 'RMP3':  // RMP3 is identical to RIFF, just renamed. Used by [unknown program] when creating RIFF-MP3s
				//$info['fileformat']   = 'riff';
				$this->container = 'riff';
				$thisfile_riff['header_size'] = $this->EitherEndian2Int($RIFFsize);
				if ($RIFFsubtype == 'RMP3') {
					// RMP3 is identical to WAVE, just renamed. Used by [unknown program] when creating RIFF-MP3s
					$RIFFsubtype = 'WAVE';
				}
				if ($RIFFsubtype != 'AMV ') {
					// AMV files are RIFF-AVI files with parts of the spec deliberately broken, such as chunk size fields hardcoded to zero (because players known in hardware that these fields are always a certain size
					// Handled separately in ParseRIFFAMV()
					$thisfile_riff[$RIFFsubtype]  = $this->ParseRIFF($offset, ($offset + $thisfile_riff['header_size'] - 4));
				}
				if (($info['avdataend'] - $info['filesize']) == 1) {
					// LiteWave appears to incorrectly *not* pad actual output file
					// to nearest WORD boundary so may appear to be short by one
					// byte, in which case - skip warning
					$info['avdataend'] = $info['filesize'];
				}

				$nextRIFFoffset = $Original['avdataoffset'] + 8 + $thisfile_riff['header_size']; // 8 = "RIFF" + 32-bit offset
				while ($nextRIFFoffset < min($info['filesize'], $info['avdataend'])) {
					try {
						$this->fseek($nextRIFFoffset);
					} catch (getid3_exception $e) {
						if ($e->getCode() == 10) {
							//$this->warning('RIFF parser: '.$e->getMessage());
							$this->error('AVI extends beyond '.round(PHP_INT_MAX / 1073741824).'GB and PHP filesystem functions cannot read that far, playtime may be wrong');
							$this->warning('[avdataend] value may be incorrect, multiple AVIX chunks may be present');
							break;
						} else {
							throw $e;
						}
					}
					$nextRIFFheader = $this->fread(12);
					if ($nextRIFFoffset == ($info['avdataend'] - 1)) {
						if (substr($nextRIFFheader, 0, 1) == "\x00") {
							// RIFF padded to WORD boundary, we're actually already at the end
							break;
						}
					}
					$nextRIFFheaderID =                         substr($nextRIFFheader, 0, 4);
					$nextRIFFsize     = $this->EitherEndian2Int(substr($nextRIFFheader, 4, 4));
					$nextRIFFtype     =                         substr($nextRIFFheader, 8, 4);
					$chunkdata = array();
					$chunkdata['offset'] = $nextRIFFoffset + 8;
					$chunkdata['size']   = $nextRIFFsize;
					$nextRIFFoffset = $chunkdata['offset'] + $chunkdata['size'];

					switch ($nextRIFFheaderID) {
						case 'RIFF':
							$chunkdata['chunks'] = $this->ParseRIFF($chunkdata['offset'] + 4, $nextRIFFoffset);
							if (!isset($thisfile_riff[$nextRIFFtype])) {
								$thisfile_riff[$nextRIFFtype] = array();
							}
							$thisfile_riff[$nextRIFFtype][] = $chunkdata;
							break;

						case 'AMV ':
							unset($info['riff']);
							$info['amv'] = $this->ParseRIFFAMV($chunkdata['offset'] + 4, $nextRIFFoffset);
							break;

						case 'JUNK':
							// ignore
							$thisfile_riff[$nextRIFFheaderID][] = $chunkdata;
							break;

						case 'IDVX':
							$info['divxtag']['comments'] = self::ParseDIVXTAG($this->fread($chunkdata['size']));
							break;

						default:
							if ($info['filesize'] == ($chunkdata['offset'] - 8 + 128)) {
								$DIVXTAG = $nextRIFFheader.$this->fread(128 - 12);
								if (substr($DIVXTAG, -7) == 'DIVXTAG') {
									// DIVXTAG is supposed to be inside an IDVX chunk in a LIST chunk, but some bad encoders just slap it on the end of a file
									$this->warning('Found wrongly-structured DIVXTAG at offset '.($this->ftell() - 128).', parsing anyway');
									$info['divxtag']['comments'] = self::ParseDIVXTAG($DIVXTAG);
									break 2;
								}
							}
							$this->warning('Expecting "RIFF|JUNK|IDVX" at '.$nextRIFFoffset.', found "'.$nextRIFFheaderID.'" ('.getid3_lib::PrintHexBytes($nextRIFFheaderID).') - skipping rest of file');
							break 2;

					}

				}
				if ($RIFFsubtype == 'WAVE') {
					$thisfile_riff_WAVE = &$thisfile_riff['WAVE'];
				}
				break;

			default:
				$this->error('Cannot parse RIFF (this is maybe not a RIFF / WAV / AVI file?) - expecting "FORM|RIFF|SDSS|RMP3" found "'.$RIFFsubtype.'" instead');
				//unset($info['fileformat']);
				return false;
		}

		$streamindex = 0;
		switch ($RIFFsubtype) {

			// http://en.wikipedia.org/wiki/Wav
			case 'WAVE':
				$info['fileformat'] = 'wav';

				if (empty($thisfile_audio['bitrate_mode'])) {
					$thisfile_audio['bitrate_mode'] = 'cbr';
				}
				if (empty($thisfile_audio_dataformat)) {
					$thisfile_audio_dataformat = 'wav';
				}

				if (isset($thisfile_riff_WAVE['data'][0]['offset'])) {
					$info['avdataoffset'] = $thisfile_riff_WAVE['data'][0]['offset'] + 8;
					$info['avdataend']    = $info['avdataoffset'] + $thisfile_riff_WAVE['data'][0]['size'];
				}
				if (isset($thisfile_riff_WAVE['fmt '][0]['data'])) {

					$thisfile_riff_audio[$streamindex] = self::parseWAVEFORMATex($thisfile_riff_WAVE['fmt '][0]['data']);
					$thisfile_audio['wformattag'] = $thisfile_riff_audio[$streamindex]['raw']['wFormatTag'];
					if (!isset($thisfile_riff_audio[$streamindex]['bitrate']) || ($thisfile_riff_audio[$streamindex]['bitrate'] == 0)) {
						$this->error('Corrupt RIFF file: bitrate_audio == zero');
						return false;
					}
					$thisfile_riff_raw['fmt '] = $thisfile_riff_audio[$streamindex]['raw'];
					unset($thisfile_riff_audio[$streamindex]['raw']);
					$thisfile_audio['streams'][$streamindex] = $thisfile_riff_audio[$streamindex];

					$thisfile_audio = getid3_lib::array_merge_noclobber($thisfile_audio, $thisfile_riff_audio[$streamindex]);
					if (substr($thisfile_audio['codec'], 0, strlen('unknown: 0x')) == 'unknown: 0x') {
						$this->warning('Audio codec = '.$thisfile_audio['codec']);
					}
					$thisfile_audio['bitrate'] = $thisfile_riff_audio[$streamindex]['bitrate'];

					if (empty($info['playtime_seconds'])) { // may already be set (e.g. DTS-WAV)
						$info['playtime_seconds'] = (float) ((($info['avdataend'] - $info['avdataoffset']) * 8) / $thisfile_audio['bitrate']);
					}

					$thisfile_audio['lossless'] = false;
					if (isset($thisfile_riff_WAVE['data'][0]['offset']) && isset($thisfile_riff_raw['fmt ']['wFormatTag'])) {
						switch ($thisfile_riff_raw['fmt ']['wFormatTag']) {

							case 0x0001:  // PCM
								$thisfile_audio['lossless'] = true;
								break;

							case 0x2000:  // AC-3
								$thisfile_audio_dataformat = 'ac3';
								break;

							default:
								// do nothing
								break;

						}
					}
					$thisfile_audio['streams'][$streamindex]['wformattag']   = $thisfile_audio['wformattag'];
					$thisfile_audio['streams'][$streamindex]['bitrate_mode'] = $thisfile_audio['bitrate_mode'];
					$thisfile_audio['streams'][$streamindex]['lossless']     = $thisfile_audio['lossless'];
					$thisfile_audio['streams'][$streamindex]['dataformat']   = $thisfile_audio_dataformat;
				}

				if (isset($thisfile_riff_WAVE['rgad'][0]['data'])) {

					// shortcuts
					$rgadData = &$thisfile_riff_WAVE['rgad'][0]['data'];
					$thisfile_riff_raw['rgad']    = array('track'=>array(), 'album'=>array());
					$thisfile_riff_raw_rgad       = &$thisfile_riff_raw['rgad'];
					$thisfile_riff_raw_rgad_track = &$thisfile_riff_raw_rgad['track'];
					$thisfile_riff_raw_rgad_album = &$thisfile_riff_raw_rgad['album'];

					$thisfile_riff_raw_rgad['fPeakAmplitude']      = getid3_lib::LittleEndian2Float(substr($rgadData, 0, 4));
					$thisfile_riff_raw_rgad['nRadioRgAdjust']      =        $this->EitherEndian2Int(substr($rgadData, 4, 2));
					$thisfile_riff_raw_rgad['nAudiophileRgAdjust'] =        $this->EitherEndian2Int(substr($rgadData, 6, 2));

					$nRadioRgAdjustBitstring      = str_pad(getid3_lib::Dec2Bin($thisfile_riff_raw_rgad['nRadioRgAdjust']), 16, '0', STR_PAD_LEFT);
					$nAudiophileRgAdjustBitstring = str_pad(getid3_lib::Dec2Bin($thisfile_riff_raw_rgad['nAudiophileRgAdjust']), 16, '0', STR_PAD_LEFT);
					$thisfile_riff_raw_rgad_track['name']       = getid3_lib::Bin2Dec(substr($nRadioRgAdjustBitstring, 0, 3));
					$thisfile_riff_raw_rgad_track['originator'] = getid3_lib::Bin2Dec(substr($nRadioRgAdjustBitstring, 3, 3));
					$thisfile_riff_raw_rgad_track['signbit']    = getid3_lib::Bin2Dec(substr($nRadioRgAdjustBitstring, 6, 1));
					$thisfile_riff_raw_rgad_track['adjustment'] = getid3_lib::Bin2Dec(substr($nRadioRgAdjustBitstring, 7, 9));
					$thisfile_riff_raw_rgad_album['name']       = getid3_lib::Bin2Dec(substr($nAudiophileRgAdjustBitstring, 0, 3));
					$thisfile_riff_raw_rgad_album['originator'] = getid3_lib::Bin2Dec(substr($nAudiophileRgAdjustBitstring, 3, 3));
					$thisfile_riff_raw_rgad_album['signbit']    = getid3_lib::Bin2Dec(substr($nAudiophileRgAdjustBitstring, 6, 1));
					$thisfile_riff_raw_rgad_album['adjustment'] = getid3_lib::Bin2Dec(substr($nAudiophileRgAdjustBitstring, 7, 9));

					$thisfile_riff['rgad']['peakamplitude'] = $thisfile_riff_raw_rgad['fPeakAmplitude'];
					if (($thisfile_riff_raw_rgad_track['name'] != 0) && ($thisfile_riff_raw_rgad_track['originator'] != 0)) {
						$thisfile_riff['rgad']['track']['name']            = getid3_lib::RGADnameLookup($thisfile_riff_raw_rgad_track['name']);
						$thisfile_riff['rgad']['track']['originator']      = getid3_lib::RGADoriginatorLookup($thisfile_riff_raw_rgad_track['originator']);
						$thisfile_riff['rgad']['track']['adjustment']      = getid3_lib::RGADadjustmentLookup($thisfile_riff_raw_rgad_track['adjustment'], $thisfile_riff_raw_rgad_track['signbit']);
					}
					if (($thisfile_riff_raw_rgad_album['name'] != 0) && ($thisfile_riff_raw_rgad_album['originator'] != 0)) {
						$thisfile_riff['rgad']['album']['name']       = getid3_lib::RGADnameLookup($thisfile_riff_raw_rgad_album['name']);
						$thisfile_riff['rgad']['album']['originator'] = getid3_lib::RGADoriginatorLookup($thisfile_riff_raw_rgad_album['originator']);
						$thisfile_riff['rgad']['album']['adjustment'] = getid3_lib::RGADadjustmentLookup($thisfile_riff_raw_rgad_album['adjustment'], $thisfile_riff_raw_rgad_album['signbit']);
					}
				}

				if (isset($thisfile_riff_WAVE['fact'][0]['data'])) {
					$thisfile_riff_raw['fact']['NumberOfSamples'] = $this->EitherEndian2Int(substr($thisfile_riff_WAVE['fact'][0]['data'], 0, 4));

					// This should be a good way of calculating exact playtime,
					// but some sample files have had incorrect number of samples,
					// so cannot use this method

					// if (!empty($thisfile_riff_raw['fmt ']['nSamplesPerSec'])) {
					//     $info['playtime_seconds'] = (float) $thisfile_riff_raw['fact']['NumberOfSamples'] / $thisfile_riff_raw['fmt ']['nSamplesPerSec'];
					// }
				}
				if (!empty($thisfile_riff_raw['fmt ']['nAvgBytesPerSec'])) {
					$thisfile_audio['bitrate'] = getid3_lib::CastAsInt($thisfile_riff_raw['fmt ']['nAvgBytesPerSec'] * 8);
				}

				if (isset($thisfile_riff_WAVE['bext'][0]['data'])) {
					// shortcut
					$thisfile_riff_WAVE_bext_0 = &$thisfile_riff_WAVE['bext'][0];

					$thisfile_riff_WAVE_bext_0['title']          =                         trim(substr($thisfile_riff_WAVE_bext_0['data'],   0, 256));
					$thisfile_riff_WAVE_bext_0['author']         =                         trim(substr($thisfile_riff_WAVE_bext_0['data'], 256,  32));
					$thisfile_riff_WAVE_bext_0['reference']      =                         trim(substr($thisfile_riff_WAVE_bext_0['data'], 288,  32));
					$thisfile_riff_WAVE_bext_0['origin_date']    =                              substr($thisfile_riff_WAVE_bext_0['data'], 320,  10);
					$thisfile_riff_WAVE_bext_0['origin_time']    =                              substr($thisfile_riff_WAVE_bext_0['data'], 330,   8);
					$thisfile_riff_WAVE_bext_0['time_reference'] = getid3_lib::LittleEndian2Int(substr($thisfile_riff_WAVE_bext_0['data'], 338,   8));
					$thisfile_riff_WAVE_bext_0['bwf_version']    = getid3_lib::LittleEndian2Int(substr($thisfile_riff_WAVE_bext_0['data'], 346,   1));
					$thisfile_riff_WAVE_bext_0['reserved']       =                              substr($thisfile_riff_WAVE_bext_0['data'], 347, 254);
					$thisfile_riff_WAVE_bext_0['coding_history'] =         explode("\r\n", trim(substr($thisfile_riff_WAVE_bext_0['data'], 601)));
					if (preg_match('#^([0-9]{4}).([0-9]{2}).([0-9]{2})$#', $thisfile_riff_WAVE_bext_0['origin_date'], $matches_bext_date)) {
						if (preg_match('#^([0-9]{2}).([0-9]{2}).([0-9]{2})$#', $thisfile_riff_WAVE_bext_0['origin_time'], $matches_bext_time)) {
							list($dummy, $bext_timestamp['year'], $bext_timestamp['month'],  $bext_timestamp['day'])    = $matches_bext_date;
							list($dummy, $bext_timestamp['hour'], $bext_timestamp['minute'], $bext_timestamp['second']) = $matches_bext_time;
							$thisfile_riff_WAVE_bext_0['origin_date_unix'] = gmmktime($bext_timestamp['hour'], $bext_timestamp['minute'], $bext_timestamp['second'], $bext_timestamp['month'], $bext_timestamp['day'], $bext_timestamp['year']);
						} else {
							$this->warning('RIFF.WAVE.BEXT.origin_time is invalid');
						}
					} else {
						$this->warning('RIFF.WAVE.BEXT.origin_date is invalid');
					}
					$thisfile_riff['comments']['author'][] = $thisfile_riff_WAVE_bext_0['author'];
					$thisfile_riff['comments']['title'][]  = $thisfile_riff_WAVE_bext_0['title'];
				}

				if (isset($thisfile_riff_WAVE['MEXT'][0]['data'])) {
					// shortcut
					$thisfile_riff_WAVE_MEXT_0 = &$thisfile_riff_WAVE['MEXT'][0];

					$thisfile_riff_WAVE_MEXT_0['raw']['sound_information']      = getid3_lib::LittleEndian2Int(substr($thisfile_riff_WAVE_MEXT_0['data'], 0, 2));
					$thisfile_riff_WAVE_MEXT_0['flags']['homogenous']           = (bool) ($thisfile_riff_WAVE_MEXT_0['raw']['sound_information'] & 0x0001);
					if ($thisfile_riff_WAVE_MEXT_0['flags']['homogenous']) {
						$thisfile_riff_WAVE_MEXT_0['flags']['padding']          = ($thisfile_riff_WAVE_MEXT_0['raw']['sound_information'] & 0x0002) ? false : true;
						$thisfile_riff_WAVE_MEXT_0['flags']['22_or_44']         =        (bool) ($thisfile_riff_WAVE_MEXT_0['raw']['sound_information'] & 0x0004);
						$thisfile_riff_WAVE_MEXT_0['flags']['free_format']      =        (bool) ($thisfile_riff_WAVE_MEXT_0['raw']['sound_information'] & 0x0008);

						$thisfile_riff_WAVE_MEXT_0['nominal_frame_size']        = getid3_lib::LittleEndian2Int(substr($thisfile_riff_WAVE_MEXT_0['data'], 2, 2));
					}
					$thisfile_riff_WAVE_MEXT_0['anciliary_data_length']         = getid3_lib::LittleEndian2Int(substr($thisfile_riff_WAVE_MEXT_0['data'], 6, 2));
					$thisfile_riff_WAVE_MEXT_0['raw']['anciliary_data_def']     = getid3_lib::LittleEndian2Int(substr($thisfile_riff_WAVE_MEXT_0['data'], 8, 2));
					$thisfile_riff_WAVE_MEXT_0['flags']['anciliary_data_left']  = (bool) ($thisfile_riff_WAVE_MEXT_0['raw']['anciliary_data_def'] & 0x0001);
					$thisfile_riff_WAVE_MEXT_0['flags']['anciliary_data_free']  = (bool) ($thisfile_riff_WAVE_MEXT_0['raw']['anciliary_data_def'] & 0x0002);
					$thisfile_riff_WAVE_MEXT_0['flags']['anciliary_data_right'] = (bool) ($thisfile_riff_WAVE_MEXT_0['raw']['anciliary_data_def'] & 0x0004);
				}

				if (isset($thisfile_riff_WAVE['cart'][0]['data'])) {
					// shortcut
					$thisfile_riff_WAVE_cart_0 = &$thisfile_riff_WAVE['cart'][0];

					$thisfile_riff_WAVE_cart_0['version']              =                              substr($thisfile_riff_WAVE_cart_0['data'],   0,  4);
					$thisfile_riff_WAVE_cart_0['title']                =                         trim(substr($thisfile_riff_WAVE_cart_0['data'],   4, 64));
					$thisfile_riff_WAVE_cart_0['artist']               =                         trim(substr($thisfile_riff_WAVE_cart_0['data'],  68, 64));
					$thisfile_riff_WAVE_cart_0['cut_id']               =                         trim(substr($thisfile_riff_WAVE_cart_0['data'], 132, 64));
					$thisfile_riff_WAVE_cart_0['client_id']            =                         trim(substr($thisfile_riff_WAVE_cart_0['data'], 196, 64));
					$thisfile_riff_WAVE_cart_0['category']             =                         trim(substr($thisfile_riff_WAVE_cart_0['data'], 260, 64));
					$thisfile_riff_WAVE_cart_0['classification']       =                         trim(substr($thisfile_riff_WAVE_cart_0['data'], 324, 64));
					$thisfile_riff_WAVE_cart_0['out_cue']              =                         trim(substr($thisfile_riff_WAVE_cart_0['data'], 388, 64));
					$thisfile_riff_WAVE_cart_0['start_date']           =                         trim(substr($thisfile_riff_WAVE_cart_0['data'], 452, 10));
					$thisfile_riff_WAVE_cart_0['start_time']           =                         trim(substr($thisfile_riff_WAVE_cart_0['data'], 462,  8));
					$thisfile_riff_WAVE_cart_0['end_date']             =                         trim(substr($thisfile_riff_WAVE_cart_0['data'], 470, 10));
					$thisfile_riff_WAVE_cart_0['end_time']             =                         trim(substr($thisfile_riff_WAVE_cart_0['data'], 480,  8));
					$thisfile_riff_WAVE_cart_0['producer_app_id']      =                         trim(substr($thisfile_riff_WAVE_cart_0['data'], 488, 64));
					$thisfile_riff_WAVE_cart_0['producer_app_version'] =                         trim(substr($thisfile_riff_WAVE_cart_0['data'], 552, 64));
					$thisfile_riff_WAVE_cart_0['user_defined_text']    =                         trim(substr($thisfile_riff_WAVE_cart_0['data'], 616, 64));
					$thisfile_riff_WAVE_cart_0['zero_db_reference']    = getid3_lib::LittleEndian2Int(substr($thisfile_riff_WAVE_cart_0['data'], 680,  4), true);
					for ($i = 0; $i < 8; $i++) {
						$thisfile_riff_WAVE_cart_0['post_time'][$i]['usage_fourcc'] =                  substr($thisfile_riff_WAVE_cart_0['data'], 684 + ($i * 8), 4);
						$thisfile_riff_WAVE_cart_0['post_time'][$i]['timer_value']  = getid3_lib::LittleEndian2Int(substr($thisfile_riff_WAVE_cart_0['data'], 684 + ($i * 8) + 4, 4));
					}
					$thisfile_riff_WAVE_cart_0['url']              =                 trim(substr($thisfile_riff_WAVE_cart_0['data'],  748, 1024));
					$thisfile_riff_WAVE_cart_0['tag_text']         = explode("\r\n", trim(substr($thisfile_riff_WAVE_cart_0['data'], 1772)));

					$thisfile_riff['comments']['artist'][] = $thisfile_riff_WAVE_cart_0['artist'];
					$thisfile_riff['comments']['title'][]  = $thisfile_riff_WAVE_cart_0['title'];
				}

				if (isset($thisfile_riff_WAVE['SNDM'][0]['data'])) {
					// SoundMiner metadata

					// shortcuts
					$thisfile_riff_WAVE_SNDM_0      = &$thisfile_riff_WAVE['SNDM'][0];
					$thisfile_riff_WAVE_SNDM_0_data = &$thisfile_riff_WAVE_SNDM_0['data'];
					$SNDM_startoffset = 0;
					$SNDM_endoffset   = $thisfile_riff_WAVE_SNDM_0['size'];

					while ($SNDM_startoffset < $SNDM_endoffset) {
						$SNDM_thisTagOffset = 0;
						$SNDM_thisTagSize      = getid3_lib::BigEndian2Int(substr($thisfile_riff_WAVE_SNDM_0_data, $SNDM_startoffset + $SNDM_thisTagOffset, 4));
						$SNDM_thisTagOffset += 4;
						$SNDM_thisTagKey       =                           substr($thisfile_riff_WAVE_SNDM_0_data, $SNDM_startoffset + $SNDM_thisTagOffset, 4);
						$SNDM_thisTagOffset += 4;
						$SNDM_thisTagDataSize  = getid3_lib::BigEndian2Int(substr($thisfile_riff_WAVE_SNDM_0_data, $SNDM_startoffset + $SNDM_thisTagOffset, 2));
						$SNDM_thisTagOffset += 2;
						$SNDM_thisTagDataFlags = getid3_lib::BigEndian2Int(substr($thisfile_riff_WAVE_SNDM_0_data, $SNDM_startoffset + $SNDM_thisTagOffset, 2));
						$SNDM_thisTagOffset += 2;
						$SNDM_thisTagDataText =                            substr($thisfile_riff_WAVE_SNDM_0_data, $SNDM_startoffset + $SNDM_thisTagOffset, $SNDM_thisTagDataSize);
						$SNDM_thisTagOffset += $SNDM_thisTagDataSize;

						if ($SNDM_thisTagSize != (4 + 4 + 2 + 2 + $SNDM_thisTagDataSize)) {
							$this->warning('RIFF.WAVE.SNDM.data contains tag not expected length (expected: '.$SNDM_thisTagSize.', found: '.(4 + 4 + 2 + 2 + $SNDM_thisTagDataSize).') at offset '.$SNDM_startoffset.' (file offset '.($thisfile_riff_WAVE_SNDM_0['offset'] + $SNDM_startoffset).')');
							break;
						} elseif ($SNDM_thisTagSize <= 0) {
							$this->warning('RIFF.WAVE.SNDM.data contains zero-size tag at offset '.$SNDM_startoffset.' (file offset '.($thisfile_riff_WAVE_SNDM_0['offset'] + $SNDM_startoffset).')');
							break;
						}
						$SNDM_startoffset += $SNDM_thisTagSize;

						$thisfile_riff_WAVE_SNDM_0['parsed_raw'][$SNDM_thisTagKey] = $SNDM_thisTagDataText;
						if ($parsedkey = self::waveSNDMtagLookup($SNDM_thisTagKey)) {
							$thisfile_riff_WAVE_SNDM_0['parsed'][$parsedkey] = $SNDM_thisTagDataText;
						} else {
							$this->warning('RIFF.WAVE.SNDM contains unknown tag "'.$SNDM_thisTagKey.'" at offset '.$SNDM_startoffset.' (file offset '.($thisfile_riff_WAVE_SNDM_0['offset'] + $SNDM_startoffset).')');
						}
					}

					$tagmapping = array(
						'tracktitle'=>'title',
						'category'  =>'genre',
						'cdtitle'   =>'album',
						'tracktitle'=>'title',
					);
					foreach ($tagmapping as $fromkey => $tokey) {
						if (isset($thisfile_riff_WAVE_SNDM_0['parsed'][$fromkey])) {
							$thisfile_riff['comments'][$tokey][] = $thisfile_riff_WAVE_SNDM_0['parsed'][$fromkey];
						}
					}
				}

				if (isset($thisfile_riff_WAVE['iXML'][0]['data'])) {
					// requires functions simplexml_load_string and get_object_vars
					if ($parsedXML = getid3_lib::XML2array($thisfile_riff_WAVE['iXML'][0]['data'])) {
						$thisfile_riff_WAVE['iXML'][0]['parsed'] = $parsedXML;
						if (isset($parsedXML['SPEED']['MASTER_SPEED'])) {
							@list($numerator, $denominator) = explode('/', $parsedXML['SPEED']['MASTER_SPEED']);
							$thisfile_riff_WAVE['iXML'][0]['master_speed'] = $numerator / ($denominator ? $denominator : 1000);
						}
						if (isset($parsedXML['SPEED']['TIMECODE_RATE'])) {
							@list($numerator, $denominator) = explode('/', $parsedXML['SPEED']['TIMECODE_RATE']);
							$thisfile_riff_WAVE['iXML'][0]['timecode_rate'] = $numerator / ($denominator ? $denominator : 1000);
						}
						if (isset($parsedXML['SPEED']['TIMESTAMP_SAMPLES_SINCE_MIDNIGHT_LO']) && !empty($parsedXML['SPEED']['TIMESTAMP_SAMPLE_RATE']) && !empty($thisfile_riff_WAVE['iXML'][0]['timecode_rate'])) {
							$samples_since_midnight = floatval(ltrim($parsedXML['SPEED']['TIMESTAMP_SAMPLES_SINCE_MIDNIGHT_HI'].$parsedXML['SPEED']['TIMESTAMP_SAMPLES_SINCE_MIDNIGHT_LO'], '0'));
							$timestamp_sample_rate = (is_array($parsedXML['SPEED']['TIMESTAMP_SAMPLE_RATE']) ? max($parsedXML['SPEED']['TIMESTAMP_SAMPLE_RATE']) : $parsedXML['SPEED']['TIMESTAMP_SAMPLE_RATE']); // XML could possibly contain more than one TIMESTAMP_SAMPLE_RATE tag, returning as array instead of integer [why? does it make sense? perhaps doesn't matter but getID3 needs to deal with it] - see https://github.com/JamesHeinrich/getID3/issues/105
							$thisfile_riff_WAVE['iXML'][0]['timecode_seconds'] = $samples_since_midnight / $timestamp_sample_rate;
							$h = floor( $thisfile_riff_WAVE['iXML'][0]['timecode_seconds']       / 3600);
							$m = floor(($thisfile_riff_WAVE['iXML'][0]['timecode_seconds'] - ($h * 3600))      / 60);
							$s = floor( $thisfile_riff_WAVE['iXML'][0]['timecode_seconds'] - ($h * 3600) - ($m * 60));
							$f =       ($thisfile_riff_WAVE['iXML'][0]['timecode_seconds'] - ($h * 3600) - ($m * 60) - $s) * $thisfile_riff_WAVE['iXML'][0]['timecode_rate'];
							$thisfile_riff_WAVE['iXML'][0]['timecode_string']       = sprintf('%02d:%02d:%02d:%05.2f', $h, $m, $s,       $f);
							$thisfile_riff_WAVE['iXML'][0]['timecode_string_round'] = sprintf('%02d:%02d:%02d:%02d',   $h, $m, $s, round($f));
							unset($samples_since_midnight, $timestamp_sample_rate, $h, $m, $s, $f);
						}
						unset($parsedXML);
					}
				}



				if (!isset($thisfile_audio['bitrate']) && isset($thisfile_riff_audio[$streamindex]['bitrate'])) {
					$thisfile_audio['bitrate'] = $thisfile_riff_audio[$streamindex]['bitrate'];
					$info['playtime_seconds'] = (float) ((($info['avdataend'] - $info['avdataoffset']) * 8) / $thisfile_audio['bitrate']);
				}

				if (!empty($info['wavpack'])) {
					$thisfile_audio_dataformat = 'wavpack';
					$thisfile_audio['bitrate_mode'] = 'vbr';
					$thisfile_audio['encoder']      = 'WavPack v'.$info['wavpack']['version'];

					// Reset to the way it was - RIFF parsing will have messed this up
					$info['avdataend']        = $Original['avdataend'];
					$thisfile_audio['bitrate'] = (($info['avdataend'] - $info['avdataoffset']) * 8) / $info['playtime_seconds'];

					$this->fseek($info['avdataoffset'] - 44);
					$RIFFdata = $this->fread(44);
					$OrignalRIFFheaderSize = getid3_lib::LittleEndian2Int(substr($RIFFdata,  4, 4)) +  8;
					$OrignalRIFFdataSize   = getid3_lib::LittleEndian2Int(substr($RIFFdata, 40, 4)) + 44;

					if ($OrignalRIFFheaderSize > $OrignalRIFFdataSize) {
						$info['avdataend'] -= ($OrignalRIFFheaderSize - $OrignalRIFFdataSize);
						$this->fseek($info['avdataend']);
						$RIFFdata .= $this->fread($OrignalRIFFheaderSize - $OrignalRIFFdataSize);
					}

					// move the data chunk after all other chunks (if any)
					// so that the RIFF parser doesn't see EOF when trying
					// to skip over the data chunk
					$RIFFdata = substr($RIFFdata, 0, 36).substr($RIFFdata, 44).substr($RIFFdata, 36, 8);
					$getid3_riff = new getid3_riff($this->getid3);
					$getid3_riff->ParseRIFFdata($RIFFdata);
					unset($getid3_riff);
				}

				if (isset($thisfile_riff_raw['fmt ']['wFormatTag'])) {
					switch ($thisfile_riff_raw['fmt ']['wFormatTag']) {
						case 0x0001: // PCM
							if (!empty($info['ac3'])) {
								// Dolby Digital WAV files masquerade as PCM-WAV, but they're not
								$thisfile_audio['wformattag']  = 0x2000;
								$thisfile_audio['codec']       = self::wFormatTagLookup($thisfile_audio['wformattag']);
								$thisfile_audio['lossless']    = false;
								$thisfile_audio['bitrate']     = $info['ac3']['bitrate'];
								$thisfile_audio['sample_rate'] = $info['ac3']['sample_rate'];
							}
							if (!empty($info['dts'])) {
								// Dolby DTS files masquerade as PCM-WAV, but they're not
								$thisfile_audio['wformattag']  = 0x2001;
								$thisfile_audio['codec']       = self::wFormatTagLookup($thisfile_audio['wformattag']);
								$thisfile_audio['lossless']    = false;
								$thisfile_audio['bitrate']     = $info['dts']['bitrate'];
								$thisfile_audio['sample_rate'] = $info['dts']['sample_rate'];
							}
							break;
						case 0x08AE: // ClearJump LiteWave
							$thisfile_audio['bitrate_mode'] = 'vbr';
							$thisfile_audio_dataformat   = 'litewave';

							//typedef struct tagSLwFormat {
							//  WORD    m_wCompFormat;     // low byte defines compression method, high byte is compression flags
							//  DWORD   m_dwScale;         // scale factor for lossy compression
							//  DWORD   m_dwBlockSize;     // number of samples in encoded blocks
							//  WORD    m_wQuality;        // alias for the scale factor
							//  WORD    m_wMarkDistance;   // distance between marks in bytes
							//  WORD                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                 on']) {
						case 0:
							$thisfile_audio['codec']    = 'Pulse Code Modulation (PCM)';
							$thisfile_audio['lossless'] = true;
							$ActualBitsPerSample        = 8;
							break;

						case 1:
							$thisfile_audio['codec']    = 'Fibonacci-delta encoding';
							$thisfile_audio['lossless'] = false;
							$ActualBitsPerSample        = 4;
							break;

						default:
							$this->warning('Unexpected sCompression value in 8SVX.VHDR chunk - expecting 0 or 1, found "'.sCompression.'"');
							break;
					}
				}

				if (isset($thisfile_riff[$RIFFsubtype]['CHAN'][0]['data'])) {
					$ChannelsIndex = getid3_lib::BigEndian2Int(substr($thisfile_riff[$RIFFsubtype]['CHAN'][0]['data'], 0, 4));
					switch ($ChannelsIndex) {
						case 6: // Stereo
							$thisfile_audio['channels'] = 2;
							break;

						case 2: // Left channel only
						case 4: // Right channel only
							$thisfile_audio['channels'] = 1;
							break;

						default:
							$this->warning('Unexpected value in 8SVX.CHAN chunk - expecting 2 or 4 or 6, found "'.$ChannelsIndex.'"');
							break;
					}

				}

				$CommentsChunkNames = array('NAME'=>'title', 'author'=>'artist', '(c) '=>'copyright', 'ANNO'=>'comment');
				foreach ($CommentsChunkNames as $key => $value) {
					if (isset($thisfile_riff[$RIFFsubtype][$key][0]['data'])) {
						$thisfile_riff['comments'][$value][] = $thisfile_riff[$RIFFsubtype][$key][0]['data'];
					}
				}

				$thisfile_audio['bitrate'] = $thisfile_audio['sample_rate'] * $ActualBitsPerSample * $thisfile_audio['channels'];
				if (!empty($thisfile_audio['bitrate'])) {
					$info['playtime_seconds'] = ($info['avdataend'] - $info['avdataoffset']) / ($thisfile_audio['bitrate'] / 8);
				}
				break;

			case 'CDXA':
				$info['fileformat'] = 'vcd'; // Asume Video CD
				$info['mime_type']  = 'video/mpeg';

				if (!empty($thisfile_riff['CDXA']['data'][0]['size'])) {
					getid3_lib::IncludeDependency(GETID3_INCLUDEPATH.'module.audio-video.mpeg.php', __FILE__, true);

					$getid3_temp = new getID3();
					$getid3_temp->openfile($this->getid3->filename);
					$getid3_mpeg = new getid3_mpeg($getid3_temp);
					$getid3_mpeg->Analyze();
					if (empty($getid3_temp->info['error'])) {
						$info['audio']   = $getid3_temp->info['audio'];
						$info['video']   = $getid3_temp->info['video'];
						$info['mpeg']    = $getid3_temp->info['mpeg'];
						$info['warning'] = $getid3_temp->info['warning'];
					}
					unset($getid3_temp, $getid3_mpeg);
				}
				break;

			case 'WEBP':
				// https://developers.google.com/speed/webp/docs/riff_container
				// https://tools.ietf.org/html/rfc6386
				// https://chromium.googlesource.com/webm/libwebp/+/master/doc/webp-lossless-bitstream-spec.txt
				$info['fileformat'] = 'webp';
				$info['mime_type']  = 'image/webp';

				if (!empty($thisfile_riff['WEBP']['VP8 '][0]['size'])) {
					$old_offset = $this->ftell();
					$this->fseek($thisfile_riff['WEBP']['VP8 '][0]['offset'] + 8); // 4 bytes "VP8 " + 4 bytes chunk size
					$WEBP_VP8_header = $this->fread(10);
					$this->fseek($old_offset);
					if (substr($WEBP_VP8_header, 3, 3) == "\x9D\x01\x2A") {
						$thisfile_riff['WEBP']['VP8 '][0]['keyframe']   = !(getid3_lib::LittleEndian2Int(substr($WEBP_VP8_header, 0, 3)) & 0x800000);
						$thisfile_riff['WEBP']['VP8 '][0]['version']    =  (getid3_lib::LittleEndian2Int(substr($WEBP_VP8_header, 0, 3)) & 0x700000) >> 20;
						$thisfile_riff['WEBP']['VP8 '][0]['show_frame'] =  (getid3_lib::LittleEndian2Int(substr($WEBP_VP8_header, 0, 3)) & 0x080000);
						$thisfile_riff['WEBP']['VP8 '][0]['data_bytes'] =  (getid3_lib::LittleEndian2Int(substr($WEBP_VP8_header, 0, 3)) & 0x07FFFF) >>  0;

						$thisfile_riff['WEBP']['VP8 '][0]['scale_x']    =  (getid3_lib::LittleEndian2Int(substr($WEBP_VP8_header, 6, 2)) & 0xC000) >> 14;
						$thisfile_riff['WEBP']['VP8 '][0]['width']      =  (getid3_lib::LittleEndian2Int(substr($WEBP_VP8_header, 6, 2)) & 0x3FFF);
						$thisfile_riff['WEBP']['VP8 '][0]['scale_y']    =  (getid3_lib::LittleEndian2Int(substr($WEBP_VP8_header, 8, 2)) & 0xC000) >> 14;
						$thisfile_riff['WEBP']['VP8 '][0]['height']     =  (getid3_lib::LittleEndian2Int(substr($WEBP_VP8_header, 8, 2)) & 0x3FFF);

						$info['video']['resolution_x'] = $thisfile_riff['WEBP']['VP8 '][0]['width'];
						$info['video']['resolution_y'] = $thisfile_riff['WEBP']['VP8 '][0]['height'];
					} else {
						$this->error('Expecting 9D 01 2A at offset '.($thisfile_riff['WEBP']['VP8 '][0]['offset'] + 8 + 3).', found "'.getid3_lib::PrintHexBytes(substr($WEBP_VP8_header, 3, 3)).'"');
					}

				}
				if (!empty($thisfile_riff['WEBP']['VP8L'][0]['size'])) {
					$old_offset = $this->ftell();
					$this->fseek($thisfile_riff['WEBP']['VP8L'][0]['offset'] + 8); // 4 bytes "VP8L" + 4 bytes chunk size
					$WEBP_VP8L_header = $this->fread(10);
					$this->fseek($old_offset);
					if (substr($WEBP_VP8L_header, 0, 1) == "\x2F") {
						$width_height_flags = getid3_lib::LittleEndian2Bin(substr($WEBP_VP8L_header, 1, 4));
						$thisfile_riff['WEBP']['VP8L'][0]['width']         =        bindec(substr($width_height_flags, 18, 14)) + 1;
						$thisfile_riff['WEBP']['VP8L'][0]['height']        =        bindec(substr($width_height_flags,  4, 14)) + 1;
						$thisfile_riff['WEBP']['VP8L'][0]['alpha_is_used'] = (bool) bindec(substr($width_height_flags,  3,  1));
						$thisfile_riff['WEBP']['VP8L'][0]['version']       =        bindec(substr($width_height_flags,  0,  3));

						$info['video']['resolution_x'] = $thisfile_riff['WEBP']['VP8L'][0]['width'];
						$info['video']['resolution_y'] = $thisfile_riff['WEBP']['VP8L'][0]['height'];
					} else {
						$this->error('Expecting 2F at offset '.($thisfile_riff['WEBP']['VP8L'][0]['offset'] + 8).', found "'.getid3_lib::PrintHexBytes(substr($WEBP_VP8L_header, 0, 1)).'"');
					}

				}
				break;

			default:
				$this->error('Unknown RIFF type: expecting one of (WAVE|RMP3|AVI |CDDA|AIFF|AIFC|8SVX|CDXA|WEBP), found "'.$RIFFsubtype.'" instead');
				//unset($info['fileformat']);
		}

		switch ($RIFFsubtype) {
			case 'WAVE':
			case 'AIFF':
			case 'AIFC':
				$ID3v2_key_good = 'id3 ';
				$ID3v2_keys_bad = array('ID3 ', 'tag ');
				foreach ($ID3v2_keys_bad as $ID3v2_key_bad) {
					if (isset($thisfile_riff[$RIFFsubtype][$ID3v2_key_bad]) && !array_key_exists($ID3v2_key_good, $thisfile_riff[$RIFFsubtype])) {
						$thisfile_riff[$RIFFsubtype][$ID3v2_key_good] = $thisfile_riff[$RIFFsubtype][$ID3v2_key_bad];
						$this->warning('mapping "'.$ID3v2_key_bad.'" chunk to "'.$ID3v2_key_good.'"');
					}
				}

				if (isset($thisfile_riff[$RIFFsubtype]['id3 '])) {
					getid3_lib::IncludeDependency(GETID3_INCLUDEPATH.'module.tag.id3v2.php', __FILE__, true);

					$getid3_temp = new getID3();
					$getid3_temp->openfile($this->getid3->filename);
					$getid3_id3v2 = new getid3_id3v2($getid3_temp);
					$getid3_id3v2->StartingOffset = $thisfile_riff[$RIFFsubtype]['id3 '][0]['offset'] + 8;
					if ($thisfile_riff[$RIFFsubtype]['id3 '][0]['valid'] = $getid3_id3v2->Analyze()) {
						$info['id3v2'] = $getid3_temp->info['id3v2'];
					}
					unset($getid3_temp, $getid3_id3v2);
				}
				break;
		}

		if (isset($thisfile_riff_WAVE['DISP']) && is_array($thisfile_riff_WAVE['DISP'])) {
			$thisfile_riff['comments']['title'][] = trim(substr($thisfile_riff_WAVE['DISP'][count($thisfile_riff_WAVE['DISP']) - 1]['data'], 4));
		}
		if (isset($thisfile_riff_WAVE['INFO']) && is_array($thisfile_riff_WAVE['INFO'])) {
			self::parseComments($thisfile_riff_WAVE['INFO'], $thisfile_riff['comments']);
		}
		if (isset($thisfile_riff['AVI ']['INFO']) && is_array($thisfile_riff['AVI ']['INFO'])) {
			self::parseComments($thisfile_riff['AVI ']['INFO'], $thisfile_riff['comments']);
		}

		if (empty($thisfile_audio['encoder']) && !empty($info['mpeg']['audio']['LAME']['short_version'])) {
			$thisfile_audio['encoder'] = $info['mpeg']['audio']['LAME']['short_version'];
		}

		if (!isset($info['playtime_seconds'])) {
			$info['playtime_seconds'] = 0;
		}
		if (isset($thisfile_riff_raw['strh'][0]['dwLength']) && isset($thisfile_riff_raw['avih']['dwMicroSecPerFrame'])) {
			// needed for >2GB AVIs where 'avih' chunk only lists number of frames in that chunk, not entire movie
			$info['playtime_seconds'] = $thisfile_riff_raw['strh'][0]['dwLength'] * ($thisfile_riff_raw['avih']['dwMicroSecPerFrame'] / 1000000);
		} elseif (isset($thisfile_riff_raw['avih']['dwTotalFrames']) && isset($thisfile_riff_raw['avih']['dwMicroSecPerFrame'])) {
			$info['playtime_seconds'] = $thisfile_riff_raw['avih']['dwTotalFrames'] * ($thisfile_riff_raw['avih']['dwMicroSecPerFrame'] / 1000000);
		}

		if ($info['playtime_seconds'] > 0) {
			if (isset($thisfile_riff_audio) && isset($thisfile_riff_video)) {

				if (!isset($info['bitrate'])) {
					$info['bitrate'] = ((($info['avdataend'] - $info['avdataoffset']) / $info['playtime_seconds']) * 8);
				}

			} elseif (isset($thisfile_riff_audio) && !isset($thisfile_riff_video)) {

				if (!isset($thisfile_audio['bitrate'])) {
					$thisfile_audio['bitrate'] = ((($info['avdataend'] - $info['avdataoffset']) / $info['playtime_seconds']) * 8);
				}

			} elseif (!isset($thisfile_riff_audio) && isset($thisfile_riff_video)) {

				if (!isset($thisfile_video['bitrate'])) {
					$thisfile_video['bitrate'] = ((($info['avdataend'] - $info['avdataoffset']) / $info['playtime_seconds']) * 8);
				}

			}
		}


		if (isset($thisfile_riff_video) && isset($thisfile_audio['bitrate']) && ($thisfile_audio['bitrate'] > 0) && ($info['playtime_seconds'] > 0)) {

			$info['bitrate'] = ((($info['avdataend'] - $info['avdataoffset']) / $info['playtime_seconds']) * 8);
			$thisfile_audio['bitrate'] = 0;
			$thisfile_video['bitrate'] = $info['bitrate'];
			foreach ($thisfile_riff_audio as $channelnumber => $audioinfoarray) {
				$thisfile_video['bitrate'] -= $audioinfoarray['bitrate'];
				$thisfile_audio['bitrate'] += $audioinfoarray['bitrate'];
			}
			if ($thisfile_video['bitrate'] <= 0) {
				unset($thisfile_video['bitrate']);
			}
			if ($thisfile_audio['bitrate'] <= 0) {
				unset($thisfile_audio['bitrate']);
			}
		}

		if (isset($info['mpeg']['audio'])) {
			$thisfile_audio_dataformat      = 'mp'.$info['mpeg']['audio']['layer'];
			$thisfile_audio['sample_rate']  = $info['mpeg']['audio']['sample_rate'];
			$thisfile_audio['channels']     = $info['mpeg']['audio']['channels'];
			$thisfile_audio['bitrate']      = $info['mpeg']['audio']['bitrate'];
			$thisfile_audio['bitrate_mode'] = strtolower($info['mpeg']['audio']['bitrate_mode']);
			if (!empty($info['mpeg']['audio']['codec'])) {
				$thisfile_audio['codec'] = $info['mpeg']['audio']['codec'].' '.$thisfile_audio['codec'];
			}
			if (!empty($thisfile_audio['streams'])) {
				foreach ($thisfile_audio['streams'] as $streamnumber => $streamdata) {
					if ($streamdata['dataformat'] == $thisfile_audio_dataformat) {
						$thisfile_audio['streams'][$streamnumber]['sample_rate']  = $thisfile_audio['sample_rate'];
						$thisfile_audio['streams'][$streamnumber]['channels']     = $thisfile_audio['channels'];
						$thisfile_audio['streams'][$streamnumber]['bitrate']      = $thisfile_audio['bitrate'];
						$thisfile_audio['streams'][$streamnumber]['bitrate_mode'] = $thisfile_audio['bitrate_mode'];
						$thisfile_audio['streams'][$streamnumber]['codec']        = $thisfile_audio['codec'];
					}
				}
			}
			$getid3_mp3 = new getid3_mp3($this->getid3);
			$thisfile_audio['encoder_options'] = $getid3_mp3->GuessEncoderOptions();
			unset($getid3_mp3);
		}


		if (!empty($thisfile_riff_raw['fmt ']['wBitsPerSample']) && ($thisfile_riff_raw['fmt ']['wBitsPerSample'] > 0)) {
			switch ($thisfile_audio_dataformat) {
				case 'ac3':
					// ignore bits_per_sample
					break;

				default:
					$thisfile_audio['bits_per_sample'] = $thisfile_riff_raw['fmt ']['wBitsPerSample'];
					break;
			}
		}


		if (empty($thisfile_riff_raw)) {
			unset($thisfile_riff['raw']);
		}
		if (empty($thisfile_riff_audio)) {
			unset($thisfile_riff['audio']);
		}
		if (empty($thisfile_riff_video)) {
			unset($thisfile_riff['video']);
		}

		return true;
	}

	public function ParseRIFFAMV($startoffset, $maxoffset) {
		// AMV files are RIFF-AVI files with parts of the spec deliberately broken, such as chunk size fields hardcoded to zero (because players known in hardware that these fields are always a certain size

		// https://code.google.com/p/amv-codec-tools/wiki/AmvDocumentation
		//typedef struct _amvmainheader {
		//FOURCC fcc; // 'amvh'
		//DWORD cb;
		//DWORD dwMicroSecPerFrame;
		//BYTE reserve[28];
		//DWORD dwWidth;
		//DWORD dwHeight;
		//DWORD dwSpeed;
		//DWORD reserve0;
		//DWORD reserve1;
		//BYTE bTimeSec;
		//BYTE bTimeMin;
		//WORD wTimeHour;
		//} AMVMAINHEADER;

		$info = &$this->getid3->info;
		$RIFFchunk = false;

		try {

			$this->fseek($startoffset);
			$maxoffset = min($maxoffset, $info['avdataend']);
			$AMVheader = $this->fread(284);
			if (substr($AMVheader,   0,  8) != 'hdrlamvh') {
				throw new Exception('expecting "hdrlamv" at offset '.($startoffset +   0).', found "'.substr($AMVheader,   0, 8).'"');
			}
			if (substr($AMVheader,   8,  4) != "\x38\x00\x00\x00") { // "amvh" chunk size, hardcoded to 0x38 = 56 bytes
				throw new Exception('expecting "0x38000000" at offset '.($startoffset +   8).', found "'.getid3_lib::PrintHexBytes(substr($AMVheader,   8, 4)).'"');
			}
			$RIFFchunk = array();
			$RIFFchunk['amvh']['us_per_frame']   = getid3_lib::LittleEndian2Int(substr($AMVheader,  12,  4));
			$RIFFchunk['amvh']['reserved28']     =                              substr($AMVheader,  16, 28);  // null? reserved?
			$RIFFchunk['amvh']['resolution_x']   = getid3_lib::LittleEndian2Int(substr($AMVheader,  44,  4));
			$RIFFchunk['amvh']['resolution_y']   = getid3_lib::LittleEndian2Int(substr($AMVheader,  48,  4));
			$RIFFchunk['amvh']['frame_rate_int'] = getid3_lib::LittleEndian2Int(substr($AMVheader,  52,  4));
			$RIFFchunk['amvh']['reserved0']      = getid3_lib::LittleEndian2Int(substr($AMVheader,  56,  4)); // 1? reserved?
			$RIFFchunk['amvh']['reserved1']      = getid3_lib::LittleEndian2Int(substr($AMVheader,  60,  4)); // 0? reserved?
			$RIFFchunk['amvh']['runtime_sec']    = getid3_lib::LittleEndian2Int(substr($AMVheader,  64,  1));
			$RIFFchunk['amvh']['runtime_min']    = getid3_lib::LittleEndian2Int(substr($AMVheader,  65,  1));
			$RIFFchunk['amvh']['runtime_hrs']    = getid3_lib::LittleEndian2Int(substr($AMVheader,  66,  2));

			$info['video']['frame_rate']   = 1000000 / $RIFFchunk['amvh']['us_per_frame'];
			$info['video']['resolution_x'] = $RIFFchunk['amvh']['resolution_x'];
			$info['video']['resolution_y'] = $RIFFchunk['amvh']['resolution_y'];
			$info['playtime_seconds']      = ($RIFFchunk['amvh']['runtime_hrs'] * 3600) + ($RIFFchunk['amvh']['runtime_min'] * 60) + $RIFFchunk['amvh']['runtime_sec'];

			// the rest is all hardcoded(?) and does not appear to be useful until you get to audio info at offset 256, even then everything is probably hardcoded

			if (substr($AMVheader,  68, 20) != 'LIST'."\x00\x00\x00\x00".'strlstrh'."\x38\x00\x00\x00") {
				throw new Exception('expecting "LIST<0x00000000>strlstrh<0x38000000>" at offset '.($startoffset +  68).', found "'.getid3_lib::PrintHexBytes(substr($AMVheader,  68, 20)).'"');
			}
			// followed by 56 bytes of null: substr($AMVheader,  88, 56) -> 144
			if (substr($AMVheader, 144,  8) != 'strf'."\x24\x00\x00\x00") {
				throw new Exception('expecting "strf<0x24000000>" at offset '.($startoffset + 144).', found "'.getid3_lib::PrintHexBytes(substr($AMVheader, 144,  8)).'"');
			}
			// followed by 36 bytes of null: substr($AMVheader, 144, 36) -> 180

			if (substr($AMVheader, 188, 20) != 'LIST'."\x00\x00\x00\x00".'strlstrh'."\x30\x00\x00\x00") {
				throw new Exception('expecting "LIST<0x00000000>strlstrh<0x30000000>" at offset '.($startoffset + 188).', found "'.getid3_lib::PrintHexBytes(substr($AMVheader, 188, 20)).'"');
			}
			// followed by 48 bytes of null: substr($AMVheader, 208, 48) -> 256
			if (substr($AMVheader, 256,  8) != 'strf'."\x14\x00\x00\x00") {
				throw new Exception('expecting "strf<0x14000000>" at offset '.($startoffset + 256).', found "'.getid3_lib::PrintHexBytes(substr($AMVheader, 256,  8)).'"');
			}
			// followed by 20 bytes of a modified WAVEFORMATEX:
			// typedef struct {
			// WORD wFormatTag;       //(Fixme: this is equal to PCM's 0x01 format code)
			// WORD nChannels;        //(Fixme: this is always 1)
			// DWORD nSamplesPerSec;  //(Fixme: for all known sample files this is equal to 22050)
			// DWORD nAvgBytesPerSec; //(Fixme: for all known sample files this is equal to 44100)
			// WORD nBlockAlign;      //(Fixme: this seems to be 2 in AMV files, is this correct ?)
			// WORD wBitsPerSample;   //(Fixme: this seems to be 16 in AMV files instead of the expected 4)
			// WORD cbSize;           //(Fixme: this seems to be 0 in AMV files)
			// WORD reserved;
			// } WAVEFORMATEX;
			$RIFFchunk['strf']['wformattag']      = getid3_lib::LittleEndian2Int(substr($AMVheader,  264,  2));
			$RIFFchunk['strf']['nchannels']       = getid3_lib::LittleEndian2Int(substr($AMVheader,  266,  2));
			$RIFFchunk['strf']['nsamplespersec']  = getid3_lib::LittleEndian2Int(substr($AMVheader,  268,  4));
			$RIFFchunk['strf']['navgbytespersec'] = getid3_lib::LittleEndian2Int(substr($AMVheader,  272,  4));
			$RIFFchunk['strf']['nblockalign']     = getid3_lib::LittleEndian2Int(substr($AMVheader,  276,  2));
			$RIFFchunk['strf']['wbitspersample']  = getid3_lib::LittleEndian2Int(substr($AMVheader,  278,  2));
			$RIFFchunk['strf']['cbsize']          = getid3_lib::LittleEndian2Int(substr($AMVheader,  280,  2));
			$RIFFchunk['strf']['reserved']        = getid3_lib::LittleEndian2Int(substr($AMVheader,  282,  2));


			$info['audio']['lossless']        = false;
			$info['audio']['sample_rate']     = $RIFFchunk['strf']['nsamplespersec'];
			$info['audio']['channels']        = $RIFFchunk['strf']['nchannels'];
			$info['audio']['bits_per_sample'] = $RIFFchunk['strf']['wbitspersample'];
			$info['audio']['bitrate']         = $info['audio']['sample_rate'] * $info['audio']['channels'] * $info['audio']['bits_per_sample'];
			$info['audio']['bitrate_mode']    = 'cbr';


		} catch (getid3_exception $e) {
			if ($e->getCode() == 10) {
				$this->warning('RIFFAMV parser: '.$e->getMessage());
			} else {
				throw $e;
			}
		}

		return $RIFFchunk;
	}


	public function ParseRIFF($startoffset, $maxoffset) {
		$info = &$this->getid3->info;

		$RIFFchunk = false;
		$FoundAllChunksWeNeed = false;

		try {
			$this->fseek($startoffset);
			$maxoffset = min($maxoffset, $info['avdataend']);
			while ($this->ftell() < $maxoffset) {
				$chunknamesize = $this->fread(8);
				//$chunkname =                          substr($chunknamesize, 0, 4);
				$chunkname = str_replace("\x00", '_', substr($chunknamesize, 0, 4));  // note: chunk names of 4 null bytes do appear to be legal (has been observed inside INFO and PRMI chunks, for example), but makes traversing array keys more difficult
				$chunksize =  $this->EitherEndian2Int(substr($chunknamesize, 4, 4));
				//if (strlen(trim($chunkname, "\x00")) < 4) {
				if (strlen($chunkname) < 4) {
					$this->error('Expecting chunk name at offset '.($this->ftell() - 8).' but found nothing. Aborting RIFF parsing.');
					break;
				}
				if (($chunksize == 0) && ($chunkname != 'JUNK')) {
					$this->warning('Chunk ('.$chunkname.') size at offset '.($this->ftell() - 4).' is zero. Aborting RIFF parsing.');
					break;
				}
				if (($chunksize % 2) != 0) {
					// all structures are packed on word boundaries
					$chunksize++;
				}

				switch ($chunkname) {
					case 'LIST':
						$listname = $this->fread(4);
						if (preg_match('#^(movi|rec )$#i', $listname)) {
							$RIFFchunk[$listname]['offset'] = $this->ftell() - 4;
							$RIFFchunk[$listname]['size']   = $chunksize;

							if (!$FoundAllChunksWeNeed) {
								$WhereWeWere      = $this->ftell();
								$AudioChunkHeader = $this->fread(12);
								$AudioChunkStreamNum  =                              substr($AudioChunkHeader, 0, 2);
								$AudioChunkStreamType =                              substr($AudioChunkHeader, 2, 2);
								$AudioChunkSize       = getid3_lib::LittleEndian2Int(substr($AudioChunkHeader, 4, 4));

								if ($AudioChunkStreamType == 'wb') {
									$FirstFourBytes = substr($AudioChunkHeader, 8, 4);
									if (preg_match('/^\xFF[\xE2-\xE7\xF2-\xF7\xFA-\xFF][\x00-\xEB]/s', $FirstFourBytes)) {
										// MP3
										if (getid3_mp3::MPEGaudioHeaderBytesValid($FirstFourBytes)) {
											$getid3_temp = new getID3();
											$getid3_temp->openfile($this->getid3->filename);
											$getid3_temp->info['avdataoffset'] = $this->ftell() - 4;
											$getid3_temp->info['avdataend']    = $this->ftell() + $AudioChunkSize;
											$getid3_mp3 = new getid3_mp3($getid3_temp, __CLASS__);
											$getid3_mp3->getOnlyMPEGaudioInfo($getid3_temp->info['avdataoffset'], false);
											if (isset($getid3_temp->info['mpeg']['audio'])) {
												$info['mpeg']['audio']         = $getid3_temp->info['mpeg']['audio'];
												$info['audio']                 = $getid3_temp->info['audio'];
												$info['audio']['dataformat']   = 'mp'.$info['mpeg']['audio']['layer'];
												$info['audio']['sample_rate']  = $info['mpeg']['audio']['sample_rate'];
												$info['audio']['channels']     = $info['mpeg']['audio']['channels'];
												$info['audio']['bitrate']      = $info['mpeg']['audio']['bitrate'];
												$info['audio']['bitrate_mode'] = strtolower($info['mpeg']['audio']['bitrate_mode']);
												//$info['bitrate']               = $info['audio']['bitrate'];
											}
											unset($getid3_temp, $getid3_mp3);
										}

									} elseif (strpos($FirstFourBytes, getid3_ac3::syncword) === 0) {

										// AC3
										$getid3_temp = new getID3();
										$getid3_temp->openfile($this->getid3->filename);
										$getid3_temp->info['avdataoffset'] = $this->ftell() - 4;
										$getid3_temp->info['avdataend']    = $this->ftell() + $AudioChunkSize;
										$getid3_ac3 = new getid3_ac3($getid3_temp);
										$getid3_ac3->Analyze();
										if (empty($getid3_temp->info['error'])) {
											$info['audio']   = $getid3_temp->info['audio'];
											$info['ac3']     = $getid3_temp->info['ac3'];
											if (!empty($getid3_temp->info['warning'])) {
												foreach ($getid3_temp->info['warning'] as $key => $value) {
													$this->warning($value);
												}
											}
										}
										unset($getid3_temp, $getid3_ac3);
									}
								}
								$FoundAllChunksWeNeed = true;
								$this->fseek($WhereWeWere);
							}
							$this->fseek($chunksize - 4, SEEK_CUR);

						} else {

							if (!isset($RIFFchunk[$listname])) {
								$RIFFchunk[$listname] = array();
							}
							$LISTchunkParent    = $listname;
							$LISTchunkMaxOffset = $this->ftell() - 4 + $chunksize;
							if ($parsedChunk = $this->ParseRIFF($this->ftell(), $LISTchunkMaxOffset)) {
								$RIFFchunk[$listname] = array_merge_recursive($RIFFchunk[$listname], $parsedChunk);
							}

						}
						break;

					default:
						if (preg_match('#^[0-9]{2}(wb|pc|dc|db)$#', $chunkname)) {
							$this->fseek($chunksize, SEEK_CUR);
							break;
						}
						$thisindex = 0;
						if (isset($RIFFchunk[$chunkname]) && is_array($RIFFchunk[$chunkname])) {
							$thisindex = count($RIFFchunk[$chunkname]);
						}
						$RIFFchunk[$chunkname][$thisindex]['offset'] = $this->ftell() - 8;
						$RIFFchunk[$chunkname][$thisindex]['size']   = $chunksize;
						switch ($chunkname) {
							case 'data':
								$info['avdataoffset'] = $this->ftell();
								$info['avdataend']    = $info['avdataoffset'] + $chunksize;

								$testData = $this->fread(36);
								if ($testData === '') {
									break;
								}
								if (preg_match('/^\xFF[\xE2-\xE7\xF2-\xF7\xFA-\xFF][\x00-\xEB]/s', substr($testData, 0, 4))) {

									// Probably is MP3 data
									if (getid3_mp3::MPEGaudioHeaderBytesValid(substr($testData, 0, 4))) {
										$getid3_temp = new getID3();
										$getid3_temp->openfile($this->getid3->filename);
										$getid3_temp->info['avdataoffset'] = $info['avdataoffset'];
										$getid3_temp->info['avdataend']    = $info['avdataend'];
										$getid3_mp3 = new getid3_mp3($getid3_temp, __CLASS__);
										$getid3_mp3->getOnlyMPEGaudioInfo($info['avdataoffset'], false);
										if (empty($getid3_temp->info['error'])) {
											$info['audio'] = $getid3_temp->info['audio'];
											$info['mpeg']  = $getid3_temp->info['mpeg'];
										}
										unset($getid3_temp, $getid3_mp3);
									}

								} elseif (($isRegularAC3 = (substr($testData, 0, 2) == getid3_ac3::syncword)) || substr($testData, 8, 2) == strrev(getid3_ac3::syncword)) {

									// This is probably AC-3 data
									$getid3_temp = new getID3();
									if ($isRegularAC3) {
										$getid3_temp->openfile($this->getid3->filename);
										$getid3_temp->info['avdataoffset'] = $info['avdataoffset'];
										$getid3_temp->info['avdataend']    = $info['avdataend'];
									}
									$getid3_ac3 = new getid3_ac3($getid3_temp);
									if ($isRegularAC3) {
										$getid3_ac3->Analyze();
									} else {
										// Dolby Digital WAV
										// AC-3 content, but not encoded in same format as normal AC-3 file
										// For one thing, byte order is swapped
										$ac3_data = '';
										for ($i = 0; $i < 28; $i += 2) {
											$ac3_data .= substr($testData, 8 + $i + 1, 1);
											$ac3_data .= substr($testData, 8 + $i + 0, 1);
										}
										$getid3_ac3->AnalyzeString($ac3_data);
									}

									if (empty($getid3_temp->info['error'])) {
										$info['audio'] = $getid3_temp->info['audio'];
										$info['ac3']   = $getid3_temp->info['ac3'];
										if (!empty($getid3_temp->info['warning'])) {
											foreach ($getid3_temp->info['warning'] as $newerror) {
												$this->warning('getid3_ac3() says: ['.$newerror.']');
											}
										}
									}
									unset($getid3_temp, $getid3_ac3);

								} elseif (preg_match('/^('.implode('|', array_map('preg_quote', getid3_dts::$syncwords)).')/', $testData)) {

									// This is probably DTS data
									$getid3_temp = new getID3();
									$getid3_temp->openfile($this->getid3->filename);
									$getid3_temp->info['avdataoffset'] = $info['avdataoffset'];
									$getid3_dts = new getid3_dts($getid3_temp);
									$getid3_dts->Analyze();
									if (empty($getid3_temp->info['error'])) {
										$info['audio']            = $getid3_temp->info['audio'];
										$info['dts']              = $getid3_temp->info['dts'];
										$info['playtime_seconds'] = $getid3_temp->info['playtime_seconds']; // may not match RIFF calculations since DTS-WAV often used 14/16 bit-word packing
										if (!empty($getid3_temp->info['warning'])) {
											foreach ($getid3_temp->info['warning'] as $newerror) {
												$this->warning('getid3_dts() says: ['.$newerror.']');
											}
										}
									}

									unset($getid3_temp, $getid3_dts);

								} elseif (substr($testData, 0, 4) == 'wvpk') {

									// This is WavPack data
									$info['wavpack']['offset'] = $info['avdataoffset'];
									$info['wavpack']['size']   = getid3_lib::LittleEndian2Int(substr($testData, 4, 4));
									$this->parseWavPackHeader(substr($testData, 8, 28));

								} else {
									// This is some other kind of data (quite possibly just PCM)
									// do nothing special, just skip it
								}
								$nextoffset = $info['avdataend'];
								$this->fseek($nextoffset);
								break;

							case 'iXML':
							case 'bext':
							case 'cart':
							case 'fmt ':
							case 'strh':
							case 'strf':
							case 'indx':
							case 'MEXT':
							case 'DISP':
								// always read data in
							case 'JUNK':
								// should be: never read data in
								// but some programs write their version strings in a JUNK chunk (e.g. VirtualDub, AVIdemux, etc)
								if ($chunksize < 1048576) {
									if ($chunksize > 0) {
										$RIFFchunk[$chunkname][$thisindex]['data'] = $this->fread($chunksize);
										if ($chunkname == 'JUNK') {
											if (preg_match('#^([\\x20-\\x7F]+)#', $RIFFchunk[$chunkname][$thisindex]['data'], $matches)) {
												// only keep text characters [chr(32)-chr(127)]
												$info['riff']['comments']['junk'][] = trim($matches[1]);
											}
											// but if nothing there, ignore
											// remove the key in either case
											unset($RIFFchunk[$chunkname][$thisindex]['data']);
										}
									}
								} else {
									$this->warning('Chunk "'.$chunkname.'" at offset '.$this->ftell().' is unexpectedly larger than 1MB (claims to be '.number_format($chunksize).' bytes), skipping data');
									$this->fseek($chunksize, SEEK_CUR);
								}
								break;

							//case 'IDVX':
							//	$info['divxtag']['comments'] = self::ParseDIVXTAG($this->fread($chunksize));
							//	break;

							default:
								if (!empty($LISTchunkParent) && (($RIFFchunk[$chunkname][$thisindex]['offset'] + $RIFFchunk[$chunkname][$thisindex]['size']) <= $LISTchunkMaxOffset)) {
									$RIFFchunk[$LISTchunkParent][$chunkname][$thisindex]['offset'] = $RIFFchunk[$chunkname][$thisindex]['offset'];
									$RIFFchunk[$LISTchunkParent][$chunkname][$thisindex]['size']   = $RIFFchunk[$chunkname][$thisindex]['size'];
									unset($RIFFchunk[$chunkname][$thisindex]['offset']);
									unset($RIFFchunk[$chunkname][$thisindex]['size']);
									if (isset($RIFFchunk[$chunkname][$thisindex]) && empty($RIFFchunk[$chunkname][$thisindex])) {
										unset($RIFFchunk[$chunkname][$thisindex]);
									}
									if (isset($RIFFchunk[$chunkname]) && empty($RIFFchunk[$chunkname])) {
										unset($RIFFchunk[$chunkname]);
									}
									$RIFFchunk[$LISTchunkParent][$chunkname][$thisindex]['data'] 