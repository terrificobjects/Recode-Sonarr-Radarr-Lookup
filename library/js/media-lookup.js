jQuery(document).ready(function($) {
    $('#rml-search-form').submit(function(e) {
        console.log('Form submitted');
        e.preventDefault();
        
        var query = $('#rml-search-query').val();
        var countryCode = $('#rml-country-code').val(); // Get the country code value

        // Check if the country code field is empty. If so, set it to "usa".
        countryCode = (countryCode === '') ? '' : countryCode;

        $.ajax({
            url: ajax_object.ajax_url, // WordPress AJAX URL
            type: 'POST',
            data: {
                action: 'rml_search_action',
                query: query,
                countryCode: countryCode  // Add the country code to the AJAX data
            },
            success: function(response) {
                $('#rml-search-results').html(response);
            }
        });
    });
});
