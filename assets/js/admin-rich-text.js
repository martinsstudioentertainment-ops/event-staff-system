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

    function isQuillEmpty(quill) {
        return quill.getText().trim() === '';
    }

    function syncQuillToTextarea(quill, textarea) {
        textarea.value = isQuillEmpty(quill) ? '' : quill.root.innerHTML;
    }

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

        var hadRequired = textarea.hasAttribute('required');
        if (hadRequired) {
            textarea.removeAttribute('required');
            textarea.dataset.richRequired = '1';
        }

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
        syncQuillToTextarea(quill, textarea);

        quill.on('text-change', function () {
            syncQuillToTextarea(quill, textarea);
        });

        textarea._quillSync = function () {
            syncQuillToTextarea(quill, textarea);
        };

        var form = textarea.closest('form');
        if (form) {
            form.addEventListener('submit', function (e) {
                syncQuillToTextarea(quill, textarea);
                if (textarea.dataset.richRequired === '1' && isQuillEmpty(quill)) {
                    e.preventDefault();
                    quill.focus();
                }
            });
        }
    });

    window.syncRichTextArea = function (el) {
        if (el && typeof el._quillSync === 'function') {
            el._quillSync();
        }
    };
})();
