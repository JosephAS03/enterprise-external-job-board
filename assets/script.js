(function(){
  function ready(fn){
    if(document.readyState === 'loading'){
      document.addEventListener('DOMContentLoaded', fn);
    } else {
      fn();
    }
  }

  function reindexRepeater(container){
    var form = container.closest('form');
    if(!form){ return; }
    var prefix = container.getAttribute('data-name') || '';
    var countInput = form.querySelector('input[name="' + prefix + '_count"]');
    if(!countInput){ return; }
    var items = container.querySelectorAll('.eejb-repeater-item');
    items.forEach(function(item, index){
      item.querySelectorAll('[data-field]').forEach(function(field){
        var key = field.getAttribute('data-field');
        field.name = prefix + '_' + key + '_' + index;
      });
    });
    countInput.value = String(items.length);
  }

  function addRepeaterItem(button){
    var type = button.getAttribute('data-type');
    var form = button.closest('form');
    if(!form){ return; }
    var prefix = type === 'education' ? 'education_history' : 'work_history';
    var container = form.querySelector('.eejb-repeater[data-name="' + prefix + '"]');
    var template = document.getElementById(type === 'education' ? 'eejb-education-template' : 'eejb-work-template');
    if(!container || !template){ return; }
    container.appendChild(template.content.cloneNode(true));
    reindexRepeater(container);
  }

  ready(function(){
    document.querySelectorAll('.eejb-repeater').forEach(function(container){
      reindexRepeater(container);
    });

    document.addEventListener('click', function(event){
      var addButton = event.target.closest('.eejb-add-repeater');
      if(addButton){
        event.preventDefault();
        addRepeaterItem(addButton);
        return;
      }
      var removeButton = event.target.closest('.eejb-remove-item');
      if(removeButton){
        event.preventDefault();
        var item = removeButton.closest('.eejb-repeater-item');
        var container = removeButton.closest('.eejb-repeater');
        if(item){ item.remove(); }
        if(container){ reindexRepeater(container); }
      }
    });
  });
})();
