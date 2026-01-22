(function($){
  function uid(prefix){
    return prefix + '_' + Math.random().toString(36).slice(2, 10);
  }

  function initCard($card){
    if(!$card || !$card.length){
      return;
    }
    if($card.data('jelykInitialized')){
      return;
    }
    $card.data('jelykInitialized', true);
  }

  function initMeaning($meaning){
    if(!$meaning || !$meaning.length){
      return;
    }
    if($meaning.data('jelykInitialized')){
      return;
    }
    $meaning.data('jelykInitialized', true);
    $meaning.find('.jelyk-card').each(function(){
      initCard($(this));
    });
  }

  function initExisting(){
    $('.jelyk-meaning').each(function(){
      initMeaning($(this));
    });
  }

  $(initExisting);

  $(document).on('click', '.jelyk-add-meaning', function(e){
    e.preventDefault();
    var meaningKey = uid('newm');
    var tpl = $('#jelyk-meaning-template').html().replaceAll('__MEANING_KEY__', meaningKey);
    var $meaning = $(tpl);
    $('.jelyk-meanings-wrap').append($meaning);
    initMeaning($meaning);
  });

  $(document).on('click', '.jelyk-remove-meaning', function(e){
    e.preventDefault();
    if(!confirm('Видалити це значення (Bedeutung) разом з усіма Cards?')){
      return;
    }
    $(this).closest('.jelyk-meaning').remove();
  });

  $(document).on('click', '.jelyk-add-card', function(e){
    e.preventDefault();
    var $meaning = $(this).closest('.jelyk-meaning');
    var meaningKey = $meaning.data('meaning-key');
    var cardKey = uid('newc');
    var tpl = $('#jelyk-card-template').html()
      .replaceAll('__MEANING_KEY__', meaningKey)
      .replaceAll('__CARD_KEY__', cardKey);
    var $card = $(tpl);
    $meaning.find('.jelyk-cards-wrap').append($card);
    initCard($card);
  });

  $(document).on('click', '.jelyk-remove-card', function(e){
    e.preventDefault();
    if(!confirm('Видалити цю картку?')){
      return;
    }
    $(this).closest('.jelyk-card').remove();
  });

  $(document).on('click', '.jelyk-toggle-translations', function(e){
    e.preventDefault();
    $(this).closest('.jelyk-card').find('.jelyk-translations').toggle();
  });

  var frame;
  $(document).on('click', '.jelyk-pick-image', function(e){
    e.preventDefault();
    var $card = $(this).closest('.jelyk-card');
    var $input = $card.find('.jelyk-image-id');
    var $preview = $card.find('.jelyk-image-preview');

    frame = wp.media({
      title: 'Оберіть зображення для Card',
      button: { text: 'Вибрати' },
      multiple: false
    });

    frame.on('select', function(){
      var attachment = frame.state().get('selection').first().toJSON();
      $input.val(attachment.id);
      if(attachment.sizes && attachment.sizes.thumbnail){
        $preview.html('<img src="'+attachment.sizes.thumbnail.url+'" style="max-width:140px;height:auto;" />');
      } else {
        $preview.html('<img src="'+attachment.url+'" style="max-width:140px;height:auto;" />');
      }
    });

    frame.open();
  });

  $(document).on('click', '.jelyk-clear-image', function(e){
    e.preventDefault();
    var $card = $(this).closest('.jelyk-card');
    $card.find('.jelyk-image-id').val('');
    $card.find('.jelyk-image-preview').html('');
  });
})(jQuery);
