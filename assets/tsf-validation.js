jQuery(document).ready(function($){
  $('#tsf-submission-form').on('submit', function(e){
    e.preventDefault();
    var $form = $(this);

    // Préparer les données à envoyer
    var formData = {
      action: 'tsf_submit',
      nonce:  tsfData.nonce,
      artist:       $('#artist').val(),
      track_title:  $('#track_title').val(),
      genre:        $('#genre').val(),
      duration:     $('#duration').val(),
      instrumental: $('#instrumental').val(),
      release_date: $('#release_date').val(),
      email:        $('#email').val(),
      phone:        $('#phone').val(),
      platform:     $('#platform').val(),
      track_url:    $('#track_url').val(),
      social_url:   $('#social_url').val(),
      type:         $('#type').val(),
      label:        $('#label').val(),
      country:      $('#country').val(),
      description:  $('#description').val(),
      optin:        $('#optin').is(':checked') ? 1 : 0,
      tsf_hp:       $('input[name="tsf_hp"]').val() // honeypot
    };

    $.post(tsfData.ajax_url, formData, function(response){
      if (response.success) {
        // Si le JSON contient bien data.redirect, on redirige
        if (response.data.redirect) {
          window.location.href = response.data.redirect;
        } else {
          $('#tsf-message').text(response.data.message).show();
        }
      } else {
        $('#tsf-message').text(response.data.message).show();
      }
    }).fail(function(jqXHR){
      var err = jqXHR.responseJSON ? jqXHR.responseJSON.data.message : 'Erreur';
      $('#tsf-message').text(err).show();
    });
  });
});
