(function () {
    'use strict';

    var textareas = document.querySelectorAll('textarea.rich-text');
    if (!textareas.length) {
        return;
    }

    var toolbarOptions = [
        [{ header: [2, 3, false] }],
        ['bold', 'italic', 'underline'],
        [{ list: 'ordered' }, { list: 'bullet' }],
        ['link'],
        ['clean'],
    ];

    textareas.forEach(function (textarea) {
        if (textarea.dataset.richReady === '1') {
            return;
        }

        var wrapper = document.createElement('div');
        wrapper.className = 'rich-text-editor';
        textarea.parentNode.insertBefore(wrapper, textarea);
        wrapper.appendChild(textarea);

        textarea.style.display = 'none';
        textarea.dataset.richReady = '1';

        var editor = document.createElement('div');
        editor.className = 'rich-text-editor__surface';
        wrapper.insertBefore(editor, textarea);

        var quill = new Quill(editor, {
            theme: 'snow',
            modules: { toolbar: toolbarOptions },
        });

        if (textarea.value.trim() !== '') {
            quill.root.innerHTML = textarea.value;
        }

        var form = textarea.closest('form');
        if (form) {
            form.addEventListener('submit', function () {
                textarea.value = quill.root.innerHTML;
            });
        }
    });
})();
