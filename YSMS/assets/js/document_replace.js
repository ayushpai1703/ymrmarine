/**
 * Document Replace / Re-upload — shared behavior for the modal in
 * includes/document_replace_modal.php, used by vessel_detail.php,
 * report_detail.php and completed_detail.php.
 *
 * Relies on the including page having already defined, in an earlier
 * <script> tag:
 *   - jQuery + SweetAlert2 + Bootstrap 5 JS (already loaded by every page)
 *   - a global CSRF_TOKEN constant (every page's own existing convention)
 * Only ever opened from a button carrying the data-doc-* attributes this
 * file reads — pages render that button ONLY for Admin/assigned-Surveyor,
 * and ajax/document_replace.php independently re-checks regardless.
 */
(function ($) {
    'use strict';

    var ALLOWED_EXT = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'xlsm', 'jpg', 'jpeg', 'png', 'zip'];
    var MAX_BYTES = 20 * 1024 * 1024;

    var $modalEl, modal, $currentDocId, selectedFile, $activeRow;

    function fmtBytes(bytes) {
        if (!bytes || bytes <= 0) return '0 KB';
        if (bytes < 1024 * 1024) return Math.round(bytes / 1024) + ' KB';
        return (bytes / 1024 / 1024).toFixed(1) + ' MB';
    }

    function extOf(name) {
        var parts = String(name || '').split('.');
        return parts.length > 1 ? parts.pop().toLowerCase() : '';
    }

    function resetModalState() {
        selectedFile = null;
        $('#drmFileInput').val('');
        $('#drmSelectedFile').hide();
        $('#drmError').hide().text('');
        $('#drmProgress').hide();
        $('#drmProgressBar').css('width', '0%');
        $('#drmReplaceBtn').prop('disabled', true);
        $('#drmDropzone').removeClass('drm-drag-over');
    }

    function showError(msg) {
        $('#drmError').text(msg).show();
    }

    function validateFile(file) {
        var ext = extOf(file.name);
        if (ALLOWED_EXT.indexOf(ext) === -1) {
            return 'That file type is not allowed. Allowed: ' + ALLOWED_EXT.join(', ') + '.';
        }
        if (file.size > MAX_BYTES) {
            return 'File is too large (max 20 MB).';
        }
        if (file.size <= 0) {
            return 'That file appears to be empty.';
        }
        return '';
    }

    function onFileChosen(file) {
        $('#drmError').hide().text('');
        var err = validateFile(file);
        if (err) {
            selectedFile = null;
            $('#drmSelectedFile').hide();
            $('#drmReplaceBtn').prop('disabled', true);
            showError(err);
            return;
        }
        selectedFile = file;
        $('#drmSelectedName').text(file.name);
        $('#drmSelectedMeta').text(extOf(file.name).toUpperCase() + ' · ' + fmtBytes(file.size));
        $('#drmSelectedFile').show();
        $('#drmReplaceBtn').prop('disabled', false);
    }

    function openModalFromButton($btn) {
        $activeRow = $btn.closest('[data-doc-row]');
        $currentDocId = $btn.data('doc-id');
        resetModalState();

        $('#drmCurrentName').text($btn.data('doc-name') || '—');
        $('#drmCurrentUploaded').text($btn.data('doc-uploaded-at') || '—');
        $('#drmCurrentUploader').text($btn.data('doc-uploaded-by') || 'Unknown');
        var typeLabel = $btn.data('doc-type') || '';
        var sizeLabel = $btn.data('doc-size') || '';
        $('#drmCurrentMeta').text([typeLabel, sizeLabel].filter(Boolean).join(' · ') || '—');

        modal.show();
    }

    function updateRowAfterReplace(upload) {
        if (!$activeRow || !$activeRow.length) return;
        $activeRow.find('[data-doc-name-display]').text(upload.file_name).attr('title', upload.file_name);
        $activeRow.find('[data-doc-size-display]').text(upload.file_size);
        $activeRow.find('[data-doc-uploaded-display]').text(upload.uploaded_at);
        $activeRow.find('.doc-view-btn').attr('href', upload.view_url);
        $activeRow.find('[data-doc-download-link]').attr('href', upload.download_url);
        $activeRow.find('.doc-replace-btn')
            .data('doc-name', upload.file_name)
            .data('doc-uploaded-at', upload.uploaded_at);
    }

    function submitReplacement() {
        if (!selectedFile || !$currentDocId) return;

        var fd = new FormData();
        fd.append('document_id', $currentDocId);
        fd.append('new_file', selectedFile);

        $('#drmReplaceBtn').prop('disabled', true);
        $('#drmProgress').show();
        $('#drmProgressBar').css('width', '2%');
        $('#drmError').hide().text('');

        $.ajax({
            url: 'ajax/document_replace.php',
            method: 'POST',
            data: fd,
            processData: false,
            contentType: false,
            dataType: 'json',
            headers: { 'X-CSRF-Token': (typeof CSRF_TOKEN !== 'undefined' ? CSRF_TOKEN : '') },
            xhr: function () {
                var xhr = $.ajaxSettings.xhr();
                if (xhr.upload) {
                    xhr.upload.addEventListener('progress', function (e) {
                        if (e.lengthComputable) {
                            var pct = Math.max(2, Math.round((e.loaded / e.total) * 100));
                            $('#drmProgressBar').css('width', pct + '%');
                        }
                    });
                }
                return xhr;
            }
        }).done(function (resp) {
            if (!resp || !resp.success) {
                $('#drmReplaceBtn').prop('disabled', false);
                $('#drmProgress').hide();
                showError((resp && resp.message) || 'Could not replace this document. Please try again.');
                return;
            }
            updateRowAfterReplace(resp.upload);
            modal.hide();
            if (window.Swal) {
                Swal.fire({ icon: 'success', title: 'Document replaced successfully.', confirmButtonColor: '#3b32b3', timer: 1800, showConfirmButton: false });
            }
        }).fail(function (xhr) {
            $('#drmReplaceBtn').prop('disabled', false);
            $('#drmProgress').hide();
            var msg = (xhr.responseJSON && xhr.responseJSON.message) || 'Could not replace this document. Please try again.';
            showError(msg);
        });
    }

    $(function () {
        $modalEl = document.getElementById('documentReplaceModal');
        if (!$modalEl) return; // page didn't include the modal partial
        modal = bootstrap.Modal.getOrCreateInstance($modalEl);

        $(document).on('click', '.doc-replace-btn', function (e) {
            e.preventDefault();
            openModalFromButton($(this));
        });

        var $dropzone = $('#drmDropzone');
        var $fileInput = $('#drmFileInput');

        $fileInput.on('change', function () {
            if (this.files && this.files[0]) onFileChosen(this.files[0]);
        });

        $dropzone.on('dragover', function (e) {
            e.preventDefault(); e.stopPropagation();
            $dropzone.addClass('drm-drag-over');
        }).on('dragleave', function (e) {
            e.preventDefault(); e.stopPropagation();
            $dropzone.removeClass('drm-drag-over');
        }).on('drop', function (e) {
            e.preventDefault(); e.stopPropagation();
            $dropzone.removeClass('drm-drag-over');
            var files = e.originalEvent.dataTransfer && e.originalEvent.dataTransfer.files;
            if (files && files[0]) onFileChosen(files[0]);
        });

        $('#drmSelectedRemove').on('click', function (e) {
            e.preventDefault(); e.stopPropagation();
            selectedFile = null;
            $fileInput.val('');
            $('#drmSelectedFile').hide();
            $('#drmReplaceBtn').prop('disabled', true);
        });

        $('#drmReplaceBtn').on('click', function () {
            if (!selectedFile) return;
            if (!window.Swal) { submitReplacement(); return; }
            Swal.fire({
                icon: 'warning',
                title: 'Replace this document?',
                text: 'The new file will become the current document. This cannot be undone.',
                showCancelButton: true,
                confirmButtonText: 'Replace Document',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#3b32b3',
                cancelButtonColor: '#64748b'
            }).then(function (result) {
                if (result.isConfirmed) submitReplacement();
            });
        });

        $modalEl.addEventListener('hidden.bs.modal', resetModalState);
    });
})(jQuery);
