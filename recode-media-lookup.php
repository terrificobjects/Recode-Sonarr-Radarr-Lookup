<?php
/**
 * Plugin Name: Recode Media Lookup
 * Author: Arufa Sari
 * Version: 1.0.0
 */

error_reporting(E_ALL); 
ini_set('display_errors', 1);

class RecodeMediaLookup {
	private $seriesNewId;
    
    public function __construct() {
        // Activation and Deactivation Hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));

        add_action('admin_init', array($this, 'register_settings'));

        // Add Admin Page
        add_action('admin_menu', array($this, 'add_admin_menu'));

        // Enqueue Admin Scripts
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts')); 

        // Add Shortcode
        add_shortcode('rml_search', array($this, 'generate_shortcode'));

        add_action('recode_media_lookup_refresh_token', array($this, 'refresh_token'));
        add_action('wp_ajax_rml_refresh_token', array($this, 'ajax_refresh_token_handler'));

        add_action('wp_ajax_rml_search_action', array($this, 'ajax_search_handler'));
        add_action('wp_ajax_nopriv_rml_search_action', array($this, 'ajax_search_handler'));

        add_filter('cron_schedules', array($this, 'add_cron_interval'));

        add_action('wp_enqueue_scripts', array($this, 'enqueue_public_scripts'));

        add_action('wp_ajax_rml_download_media', array($this, 'ajax_download_media_handler'));
        add_action('wp_ajax_nopriv_rml_download_media', array($this, 'ajax_download_media_handler'));

    }

    public function activate() {
        if (!wp_next_scheduled('recode_media_lookup_refresh_token')) {
            wp_schedule_event(time(), 'monthly', 'recode_media_lookup_refresh_token');
        }
    }
    
    public function deactivate() {
        wp_clear_scheduled_hook('recode_media_lookup_refresh_token');
    } 
    
    // Add Custom Cron Schedule for Monthly
    public function add_cron_interval($schedules) {
        $schedules['monthly'] = array(
            'interval' => 30 * DAY_IN_SECONDS, // 30 days * 24 hours * 60 minutes * 60 seconds
            'display'  => esc_html__('Every 30 Days'),
        );

        return $schedules;
    }

    public function add_admin_menu() {
        add_submenu_page('upload.php', 'Recode Media Lookup', 'Recode Media Lookup', 'manage_options', 'recode-media-lookup', array($this, 'admin_page_content'));
    }

    public function enqueue_admin_scripts($hook) {
        if ('upload.php?page=recode-media-lookup' != $hook) {
            return;
        }
        wp_enqueue_script('jquery');
    }

    public function enqueue_public_scripts() {
        wp_enqueue_script('jquery');
        wp_localize_script('rml-public-js', 'ajax_object', array('ajax_url' => admin_url('admin-ajax.php')));
        wp_enqueue_script('rml-public-js', plugin_dir_url(__FILE__) . 'library/js/media-lookup.js', array('jquery'), '1.0.2', true);
    }                 

    public function register_settings() {
        register_setting('recode-media-lookup-group', 'thetvdb_api_key');
        register_setting('recode-media-lookup-group', 'thetvdb_subscriber_pin');
        register_setting('recode-media-lookup-group', 'sonarr_api_key');
        register_setting('recode-media-lookup-group', 'sonarr_ip_address');
        register_setting('recode-media-lookup-group', 'sonarr_port');
        register_setting('recode-media-lookup-group', 'radarr_api_key');
        register_setting('recode-media-lookup-group', 'radarr_ip_address');
        register_setting('recode-media-lookup-group', 'radarr_port');
        
        add_settings_section(
            'recode-media-lookup-section', 
            'API Settings', 
            array($this, 'settings_section_callback'), 
            'recode-media-lookup-group'
        );
    
        add_settings_field(
            'thetvdb_api_key', 
            'thetvdb.com API Key', 
            array($this, 'api_key_callback'), 
            'recode-media-lookup-group', 
            'recode-media-lookup-section'
        );
        
        add_settings_field(
            'thetvdb_subscriber_pin', 
            'thetvdb.com Subscriber Pin', 
            array($this, 'subscriber_pin_callback'), 
            'recode-media-lookup-group', 
            'recode-media-lookup-section'
        );

        register_setting(
            'recode-media-lookup-group', 
            'thetvdb_last_checked',
            array(
                'default' => 0,
                'sanitize_callback' => array($this, 'renew_token_callback')
            )
        );

        add_settings_field(
            'sonarr_api_key', 
            'Sonarr API Key', 
            array($this, 'sonarr_api_key_callback'), 
            'recode-media-lookup-group', 
            'recode-media-lookup-section'
        );
    
        add_settings_field(
            'sonarr_ip_address', 
            'Sonarr IP Address', 
            array($this, 'sonarr_ip_address_callback'), 
            'recode-media-lookup-group', 
            'recode-media-lookup-section'
        );
    
        add_settings_field(
            'sonarr_port', 
            'Sonarr Port', 
            array($this, 'sonarr_port_callback'), 
            'recode-media-lookup-group', 
            'recode-media-lookup-section'
        );

        add_settings_field(
            'radarr_api_key', 
            'Radarr API Key', 
            array($this, 'radarr_api_key_callback'), 
            'recode-media-lookup-group', 
            'recode-media-lookup-section'
        );
    
        add_settings_field(
            'radarr_ip_address', 
            'Radarr IP Address', 
            array($this, 'radarr_ip_address_callback'), 
            'recode-media-lookup-group', 
            'recode-media-lookup-section'
        );
    
        add_settings_field(
            'radarr_port', 
            'Radarr Port', 
            array($this, 'radarr_port_callback'), 
            'recode-media-lookup-group', 
            'recode-media-lookup-section'
        );
    }

    public function admin_page_content() {
        ?>
        <div class="wrap">
            <h1>Recode Media Lookup</h1>
            <div class="nav-tab-wrapper">
                <a href="#" class="nav-tab nav-tab-active">Settings</a>
                <a href="#" class="nav-tab">Info</a>
            </div>
            
            <!-- Settings Tab Content -->
            <div id="settings-tab">
                <form method="post" action="options.php">
                    <?php settings_fields( 'recode-media-lookup-group' ); ?>
                    <?php do_settings_sections( 'recode-media-lookup-group' ); ?>  
                    <?php submit_button(); ?>
                </form>
                <!-- Add this line -->
                <button id="rml-refresh-token">Refresh Token</button>
            </div>
            
            <!-- Info Tab Content -->
            <div id="info-tab" style="display:none;">
                <!-- Content to come -->
            </div>
        </div>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            console.log("Admin document is ready!");  // Debug line
            $('#rml-refresh-token').click(function(e) {
                console.log("Admin button clicked!");  // Debug line
                e.preventDefault();
                $.post(ajaxurl, {action: 'rml_refresh_token'}, function(response) {
                    alert(response);
                });
            });
        });
        </script>
        <?php
    }

    public function renew_token_callback($value) {
        $last_checked = get_option('thetvdb_last_checked');
        $current_time = time();
    
        // 2592000 seconds = 30 days
        if ($current_time - $last_checked > 2592000) {
            $api_key = get_option('thetvdb_api_key');
            $pin = get_option('thetvdb_subscriber_pin');
            $this->thetvdb_login($api_key, $pin);
    
            // Update last_checked time
            update_option('thetvdb_last_checked', $current_time);
        }
    
        return $current_time;
    }    

    public function api_key_callback() {
        echo '<input type="text" name="thetvdb_api_key" value="' . esc_attr(get_option('thetvdb_api_key')) . '"/>';
    }
    
    public function subscriber_pin_callback() {
        echo '<input type="text" name="thetvdb_subscriber_pin" value="' . esc_attr(get_option('thetvdb_subscriber_pin')) . '"/>';
    }

    public function settings_section_callback() {
        echo '<p>Enter your thetvdb.com API and Subscriber details below:</p>';
    }

    public function sonarr_api_key_callback() {
        echo '<input type="text" name="sonarr_api_key" value="' . esc_attr(get_option('sonarr_api_key')) . '"/>';
    }
    
    public function sonarr_ip_address_callback() {
        echo '<input type="text" name="sonarr_ip_address" value="' . esc_attr(get_option('sonarr_ip_address')) . '"/>';
    }
    
    public function sonarr_port_callback() {
        echo '<input type="number" name="sonarr_port" min="0" max="65535" value="' . esc_attr(get_option('sonarr_port')) . '"/>';
    }
	
    public function radarr_api_key_callback() {
        echo '<input type="text" name="radarr_api_key" value="' . esc_attr(get_option('radarr_api_key')) . '"/>';
    }
    
    public function radarr_ip_address_callback() {
        echo '<input type="text" name="radarr_ip_address" value="' . esc_attr(get_option('radarr_ip_address')) . '"/>';
    }
    
    public function radarr_port_callback() {
        echo '<input type="number" name="radarr_port" min="0" max="65535" value="' . esc_attr(get_option('radarr_port')) . '"/>';
    }

    
    public function ajax_refresh_token_handler() {
        $this->refresh_token();
        echo 'Token refreshed successfully.';
        wp_die();
    }

    public function refresh_token() {
        $api_key = get_option('thetvdb_api_key');
        $pin = get_option('thetvdb_subscriber_pin');
        $this->thetvdb_login($api_key, $pin);
    
        // Update last_checked time
        update_option('thetvdb_last_checked', time());
    }

    public function thetvdb_login($api_key, $pin) {
        $data = array(
            "apikey" => $api_key,
            "pin" => $pin
        );
    
        $ch = curl_init('https://api4.thetvdb.com/v4/login');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json'
        ));
    
        $response = curl_exec($ch);
        curl_close($ch);
    
        $response_data = json_decode($response, true);
        error_log("API response: " . print_r($response_data['data']['token'], true));
        
        // Save the token into the wp_options table
        if (isset($response_data['data']['token'])) {
            update_option('thetvdb_token', $response_data['data']['token']);
            update_option('thetvdb_token_last_generated', time());
        }
    }    
    
    public function search_thetvdb($query, $country_code) {
        $api_key = get_option('thetvdb_api_key');
        $pin = get_option('thetvdb_subscriber_pin');
        $token_last_generated = get_option('thetvdb_token_last_generated', 0);
        if (time() - $token_last_generated > 30 * DAY_IN_SECONDS) {
            // Refresh the token
            $this->thetvdb_login($api_key, $pin);
        }
        
        // Retrieve the token from the database
        $token = get_option('thetvdb_token');
    
        // Create cURL session
        $ch = curl_init();
		
		// Base URL
    	$url = 'https://api4.thetvdb.com/v4/search?query=' . urlencode($query);

    	// Conditionally add the country code parameter
    	if (!empty($country_code)) {
    	    $url .= '&country=' . urlencode($country_code);
    	}
    
        // Set cURL options
        curl_setopt($ch, CURLOPT_URL, $url);
        //curl_setopt($ch, CURLOPT_URL, 'https://api4.thetvdb.com/v4/search?query=' . urlencode($query) . '&country=' . urlencode($country_code) . '');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'accept: application/json',
            'Authorization: Bearer ' . $token
        ));
    
        // Execute cURL session and get the response
        $response = curl_exec($ch);
    
        // Check for cURL errors
        if (curl_errno($ch)) {
            // Handle errors accordingly
            return "Curl error: " . curl_error($ch);
        }
    
        // Close cURL session
        curl_close($ch);
    
        // Decode the JSON response
        $response_data = json_decode($response, true);
    
        // Check if we got results or not
        if (isset($response_data['data'])) {
			error_log ('Response data tvdb search 358: ' . print_r($response_data['data'], true) );
            return $response_data['data'];
        } else {
            return 'No results found.';
        }
    }
	
	public function getSeriesNewId() {
        return $this->seriesNewId;
    }

    public function fetch_and_update_episodes($seriesId, $api_key, $ip_address, $port) {
        $seriesUrl = "http://{$ip_address}:{$port}/api/v3/series?tvdbId=$seriesId&apikey={$api_key}";
    
        // Log the URL
        error_log("Series URL: " . print_r($seriesUrl, true));
    
        $seriesContent = @file_get_contents($seriesUrl);
        if ($seriesContent === false) {
            // Handle error, e.g., log it, display a message, etc.
            error_log("Failed to fetch content from $seriesUrl");
            return;
        }
    
        $seriesArray = json_decode($seriesContent, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("JSON decoding error: " . json_last_error_msg());
            return;
        }
    
        // Log the retrieved series info
        //error_log("Series info: " . print_r($seriesArray[0]['seasons'], true));
    
        if (!isset($seriesArray[0]['seasons'])) {
            error_log("Seasons not found in series info. Here's what was found: " . print_r($seriesArray[0], true));
            return;
        }
    
        $seasons = $seriesArray[0]['seasons'];

        error_log("Type of seasons: " . gettype($seriesArray[0]['seasons']));
		
		// Update series to be monitored
		$seriesArray[0]['monitored'] = true;

		// Make a PUT request to update the series
		$seriesUpdateUrl = "http://{$ip_address}:{$port}/api/v3/series/{$seriesArray[0]['id']}";
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $seriesUpdateUrl);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($seriesArray[0]));
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, [
		    'Content-Type: application/json',
		    "X-Api-Key: {$api_key}"
		]);

		$response = curl_exec($ch);
		if (curl_errno($ch)) {
		    error_log('Curl error while updating series: ' . curl_error($ch));
		} else {
		    error_log('Curl response while updating series: ' . $response);
		}
		curl_close($ch);
    
		$completed = "no";
		
        foreach ($seriesArray[0]['seasons'] as $season) {
			$completed = "yes";
			error_log("Season Number: " . $season['seasonNumber']);
    		error_log("Is Monitored: " . $season['monitored']);
    		error_log("Total Episode Count: " . $season['statistics']['totalEpisodeCount']);
            $seasonNumber = $season['seasonNumber'];
			$seriesNewId = $seriesArray[0]['id'];
			$this->seriesNewId = $seriesArray[0]['id'];
            $episodesUrl = "http://{$ip_address}:{$port}/api/v3/episode?seriesId=$seriesNewId&seasonNumber=$seasonNumber&apikey={$api_key}";
            $episodes = json_decode(file_get_contents($episodesUrl), true);
    
            foreach ($episodes as $episode) {
                $episode['monitored'] = true;
                $episodeId = $episode['id'];
                $updateUrl = "http://{$ip_address}:{$port}/api/v3/episode/$episodeId";
    
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $updateUrl);
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($episode));
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Content-Type: application/json',
                    "X-Api-Key: {$api_key}"
                ]);
    
                $response = curl_exec($ch);
                curl_close($ch);
				
            }
        }
    }    

    public function ajax_search_handler() {
        $query = sanitize_text_field($_POST['query']);
		$country_code = isset($_POST['countryCode']) ? sanitize_text_field($_POST['countryCode']) : '';
        $results = $this->search_thetvdb($query, $country_code);
        
        foreach($results as $result) {
			if (!isset($result['year'])) { continue; }
            echo '<div style="margin-bottom: 25px;">';
            echo '  <table style="width: 100%;">';
            echo '    <tr>';
            echo '      <td rowspan="4" style="width: 20%;">';
            if (isset($result['thumbnail'])) {
                echo '        <img src="' . $result['thumbnail'] . '" alt="' . $result['name'] . ' Thumbnail" style="width: 100%;">';
            }
            echo '      </td>';
            echo '      <td style="vertical-align: top;">';
            echo '        <h2 style="display: inline;">' . $result['name'] . '</h2>';
            if (isset($result['year'])) {
                echo '        <span style="font-size: 20px;">(' . $result['year'] . ')</span>';
            }
            echo '      </td>';
            echo '    </tr>';
            if (isset($result['overview'])) {
                echo '    <tr><td style="height: 10px;"></td></tr>';  // Empty line
                echo '    <tr>';
                echo '      <td style="vertical-align: top;">' . $result['overview'] . '</td>';
                echo '    </tr>';
            }
            echo '    <tr>';
            echo '      <td style="text-align: right; font-size: 12px; vertical-align: bottom;">';
            if (isset($result['type'])) {
                echo '        Type: ' . $result['type'] . '<br>';
            }
            if (isset($result['country'])) {
                echo '        Country: ' . $result['country'] . '<br>';
            }
            if (isset($result['director'])) {
                echo '        Director: ' . $result['director'];
            }
            
            $unique_button_id = 'download_button_' . $result['id'];

            echo '<button id="' . $unique_button_id . '" class="rml-download-button" data-title="' . $result['name'] . '" data-year="' . $result['year'] . '" data-type="' . $result['type'] . '" data-search_term="' . $query . '" data-id="' . $result['id'] . '">Download</button>';
            echo '</div>';
            echo "<script type='text/javascript'>
            jQuery(document).ready(function($) {
                $('#" . $unique_button_id . "').click(function() {
                    const media_id = $(this).data('id');
                    const search_term = $(this).data('search_term');
					const media_type = $(this).data('type');
					const year = $(this).data('year');
					const title = $(this).data('title');
                    $.ajax({
                        url: ajaxurl,
                        type: 'post',
                        data: {
                            action: 'rml_download_media',
							media_id: media_id, // changed from series_id to be more general
                			search_term: title,
                			media_type: media_type,
							year: year
                        },
                        success: function(response) {
                            alert(response);
                        }
                    });
                });
            });
            </script>";
            echo '      </td>';
            echo '    </tr>';
            echo '  </table>';
            echo '</div>';
        }
        
        wp_die();
    }     

    public function ajax_download_media_handler() {
		$media_id = sanitize_text_field($_POST['media_id']);
		$media_type = sanitize_text_field($_POST['media_type']);
        //$series_id = sanitize_text_field($_POST['series_id']);
        $search_term = sanitize_text_field($_POST['search_term']);
		error_log ('search term 539: ' . print_r($search_term, true) );
		$year = filter_var($_POST['year'], FILTER_SANITIZE_NUMBER_INT);
        $media_id = str_replace('series-', '', $media_id);
        $api_key = get_option('sonarr_api_key');
        $ip_address = get_option('sonarr_ip_address');
        $port = get_option('sonarr_port');
        //error_log("Series ID: " . print_r($media_id, true));

        
        // First, check if the series exists in Sonarr.
        $series = $this->sonarr_lookup_series($search_term, $media_id);
        
        if($media_type == "movie") {
		    // Perform the lookup in Radarr using /api/v3/movie/lookup
    		$movieInfo = $this->radarr_lookup_movie($search_term, $year); // assuming you write such a function
			error_log('movieInfo value on 554: ' . print_r($movieInfo, true));
    
    		// Add to Radarr
    		$this->radarr_add_movie($movieInfo);

    		// Mark as monitored
    		$this->radarr_mark_as_monitored($movieInfo);
    
    		// Begin downloading
    		//$this->radarr_begin_download($movieInfo);
		} elseif ($series) {
            // If it does not exist, add it to Sonarr.
            if($this->sonarr_add_series($search_term, $media_id)) {
                echo "Added to Sonarr successfully.";
                $this->fetch_and_update_episodes($media_id, $api_key, $ip_address, $port);
                echo "<br />Added 'Monitored' to all episodes!";
				$ch = curl_init();

				$payload = json_encode([
		    		"name" => "SeriesSearch",
		    		"seriesId" => $this->seriesNewId
				]);

				$headers = array(
		    		"Accept: application/json",
		    		"Content-Type: application/json",
    				"X-Api-Key: {$api_key}",  // Replace with your API key
				);

				curl_setopt($ch, CURLOPT_URL, "http://{$ip_address}:{$port}/api/v3/command");
				curl_setopt($ch, CURLOPT_POST, 1);
				curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
				curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

				// Execute and get response
				$response = curl_exec($ch);

				if (curl_errno($ch)) {
		    		error_log('Curl error: ' . curl_error($ch));
				} else {
    				error_log('Curl response: ' . $response);
				}

				curl_close($ch);
				
            } else {
                echo "Failed to add to Sonarr.";
            }
        } else {
            echo "Series does not exist in Sonarr.";
        }
        wp_die();
    }
	
	// Performs lookup in Radarr and returns movie information.
	public function radarr_lookup_movie($search_term, $year) {
		error_log ('Search term for radarr_lookup_movie 611: ' . print_r($search_term, true) );
		error_log ('year for radarr_lookup_movie 612: ' . print_r($year, true) );
	    $api_key = get_option('radarr_api_key');
	    $ip_address = get_option('radarr_ip_address');
	    $port = get_option('radarr_port');
		
		$search_term_encoded = urlencode($search_term);
		
	    $url = "http://{$ip_address}:{$port}/api/v3/movie/lookup?term={$search_term_encoded}&apikey={$api_key}";
		
		error_log ('url for radarr_lookup_movie 618: ' . print_r($url, true) );

	    $headers = array(
	        "X-Api-Key: {$api_key}"
	    );

	    // Make the API call and return the data
	    // (Here, using cURL as an example, but you can use any HTTP client)
	    $ch = curl_init();
	    curl_setopt($ch, CURLOPT_URL, $url);
	    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
	    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	    $response = curl_exec($ch);
	    curl_close($ch);

	    $movies = json_decode($response, true);
		
		error_log ('Movies with null array 637: ' . print_r($movies, true) );
	
		// Filter movies based on the search term and year
		$filteredMovies = array_filter($movies, function($movie) use ($search_term, $year) {
		    return (strtolower($movie['title']) === strtolower($search_term)) && ((int)$movie['year'] === (int)$year);
		});
	
		if (!empty($filteredMovies)) {
		    // Log the first filtered movie for debugging
		    error_log('First filtered movie: ' . print_r(array_values($filteredMovies)[0], true));
		} else {
		    error_log('No movies found that match the criteria.');
		}
	
	    return $filteredMovies;
	}

	// Adds the movie to Radarr
	public function radarr_add_movie($movieInfo) {
		error_log ('Movieinfo 656: ' . print_r($movieInfo, true) );
	    $api_key = get_option('radarr_api_key');
	    $ip_address = get_option('radarr_ip_address');
	    $port = get_option('radarr_port');
		$tmdbId = $movieInfo[0]['tmdbId'];
		$movieTitle = $movieInfo[0]['title'];

	    // Serialize it as JSON
	    $payload = [
        	"title" => $movieTitle,
        	"qualityProfileId" => 6,
        	"monitored" => true,
        	"minimumAvailability" => "announced",
        	"isAvailable" => true,
        	"tmdbId" => $tmdbId,
        	"id" => 0,
        	"addOptions" => [
        	    "monitor" => "movieOnly",
        	    "searchForMovie" => true
        	],
        	"rootFolderPath" => "Z:\\GangTwo\\Media\\Movies\\"
    	];
	    $payload = json_encode($payload);
	    error_log("Here: " . print_r($payload, true));
	
	    $url = "http://{$ip_address}:{$port}/api/v3/movie";
		    $headers = array(
	        "X-Api-Key: {$api_key}",
	        "Content-Type: application/json"
	    );

	    $ch = curl_init();
	    curl_setopt($ch, CURLOPT_URL, $url);
	    curl_setopt($ch, CURLOPT_POST, true);
	    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
	    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
	    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	    $response = curl_exec($ch);
	    curl_close($ch);
	
	    return $response ? true : false;
	}

	// Marks the movie as monitored in Radarr
	public function radarr_mark_as_monitored($movieInfo) {
		$tmdbId = $movieInfo[0]['tmdbId'];
	    $movieInfo[0]['monitored'] = true;  // Set the monitored flag to true
    
	    // Reuse the add_movie function to update the movie (it's the same endpoint)
	    return $this->radarr_add_movie($movieInfo);
	}

	// Sends command to begin downloading the movie
	public function radarr_begin_download($movieInfo) {
	    $api_key = get_option('radarr_api_key');
	    $ip_address = get_option('radarr_ip_address');
	    $port = get_option('radarr_port');
		$tmdbId = $movieInfo[0]['tmdbId'];
		$movieTitle = $movieInfo[0]['title'];
		

	    $payload = json_encode([
	        "name" => "MoviesSearch",
	        "movieIds" => [$tmdbId]  // Assuming 'id' is the correct key for movie ID
	    ]);
		
    
	    $url = "http://{$ip_address}:{$port}/api/v3/command";
	    $headers = array(
	        "X-Api-Key: {$api_key}",
	        "Content-Type: application/json"
	    );

	    // Make the API call
	    $ch = curl_init();
	    curl_setopt($ch, CURLOPT_URL, $url);
	    curl_setopt($ch, CURLOPT_POST, true);
	    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
	    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
	    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	    $response = curl_exec($ch);
	    curl_close($ch);
	
	    // Check if the command was sent successfully
	    return $response ? true : false;
	}
    
    public function sonarr_lookup_series($search_term, $media_id) {
        $api_key = get_option('sonarr_api_key');
        $ip_address = get_option('sonarr_ip_address');
        $port = get_option('sonarr_port');
    
        // URL encode the search term to make it URL-safe
        $encoded_search_term = urlencode($search_term);
    
        // Add the 'term' query parameter to the URL
        $url = "http://{$ip_address}:{$port}/api/v3/series/lookup?term={$encoded_search_term}";
    
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "X-Api-Key: {$api_key}"
        ]);
    
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    
        //error_log("Sonarr response: " . print_r($response, true));
    
        if ($httpCode != 200) {
            error_log("Failed to get series from Sonarr, HTTP status code: " . $httpCode);
            return false;
        }
    
        $existing_series = json_decode($response, true);
    
        if (!is_array($existing_series)) {
            error_log("Failed to decode series from Sonarr, got: " . gettype($existing_series));
            return false;
        }
    
        // This part assumes you want to find a series by its 'tvdbId'
        foreach ($existing_series as $series) {
            if (isset($series['tvdbId']) && $series['tvdbId'] == $media_id) {
                return $series;
            }
        }
    
        return false;
    }   
    
    public function sonarr_add_series($search_term, $media_id) {
        // Get API Key, IP address, and port for Sonarr
        $api_key = get_option('sonarr_api_key');
        $ip_address = get_option('sonarr_ip_address');
        $port = get_option('sonarr_port');
    
        // Create the URL to call Sonarr's API
        $url = "http://{$ip_address}:{$port}/api/v3/series";
    
        // Construct the payload for adding a new series
        $data = [
            'title' => $search_term,
            'tvdbId' => $media_id,
            'qualityProfileId' => 1, // Add the quality profile ID here
            'seasonFolder' => true,  // Set season folder to true here
            'rootFolderPath' => "Z:\\GangTwo\\Media\\TV Shows\\",
            // Add more series options here as needed
        ];
    
        // Initialize cURL
        $ch = curl_init($url);
    
        // Configure cURL options
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            "X-Api-Key: {$api_key}"
        ]);
    
        // Execute cURL call and get the response
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
        // Close the cURL session
        curl_close($ch);
    
        // Check the HTTP status code and respond accordingly
        if ($httpCode == 201) {
            // Successfully added series
            return true;
        } else {
            // Failed to add series
            return false;
        }
    }
	
    public function generate_shortcode($atts) {
	    ob_start();
	    ?>
	    <form id="rml-search-form">
	        <input type="text" id="rml-search-query" placeholder="Search...">
	        <input type="text" style="width:100px;" class="rml-country-code" id="rml-country-code" placeholder="ex. 'usa'" maxlength="3">
	        <button type="submit">Search</button>
	    </form>
	    <div id="rml-search-results"></div>
	    <?php
	    return ob_get_clean();
	}
}

// Initialize the plugin class
$rml_plugin = new RecodeMediaLookup();