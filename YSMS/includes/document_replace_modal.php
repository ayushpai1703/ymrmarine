<!-- 🌟 Document Replace / Re-upload — shared modal, included ONCE per page
     (vessel_detail.php / report_detail.php / completed_detail.php). Opened
     by any "Replace" button carrying the data-doc-* attributes below (see
     assets/js/document_replace.js). Admin/Surveyor only — the pages that
     include this never render a Replace button for any other role, and
     ajax/document_replace.php independently re-checks regardless. -->
<div class="modal fade" id="documentReplaceModal" tabindex="-1" aria-hidden="true" data-testid="document-replace-modal">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius: 16px; overflow: hidden;">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fa-solid fa-rotate me-1"></i> Replace Document</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="drm-current">
                    <div class="drm-current-row">
                        <span class="drm-current-label">Current Document</span>
                        <span class="drm-current-value" id="drmCurrentName">—</span>
                    </div>
                    <div class="drm-current-row">
                        <span class="drm-current-label">Uploaded</span>
                        <span class="drm-current-value" id="drmCurrentUploaded">—</span>
                    </div>
                    <div class="drm-current-row">
                        <span class="drm-current-label">Uploaded By</span>
                        <span class="drm-current-value" id="drmCurrentUploader">—</span>
                    </div>
                    <div class="drm-current-row">
                        <span class="drm-current-label">File Type / Size</span>
                        <span class="drm-current-value" id="drmCurrentMeta">—</span>
                    </div>
                </div>

                <div class="drm-select-label">Select New Document</div>
                <div class="drm-dropzone" id="drmDropzone" data-testid="document-replace-dropzone">
                    <i class="fa-solid fa-file-arrow-up drm-dropzone-icon"></i>
                    <div class="drm-dropzone-text">Drag &amp; drop a replacement file here, or <span class="drm-browse-link">Browse</span></div>
                    <div class="drm-dropzone-hint">PDF, Word, Excel, JPG, PNG or ZIP</div>
                    <input type="file" id="drmFileInput" class="drm-file-input" accept=".pdf,.doc,.docx,.xls,.xlsx,.xlsm,.jpg,.jpeg,.png,.zip" data-testid="document-replace-file-input">
                </div>

                <div class="drm-selected-file" id="drmSelectedFile" style="display:none;">
                    <i class="fa-solid fa-file drm-selected-icon"></i>
                    <div class="drm-selected-info">
                        <div class="drm-selected-name" id="drmSelectedName">—</div>
                        <div class="drm-selected-meta" id="drmSelectedMeta">—</div>
                    </div>
                    <button type="button" class="drm-selected-remove" id="drmSelectedRemove" title="Remove"><i class="fa-solid fa-xmark"></i></button>
                </div>

                <div class="drm-progress" id="drmProgress" style="display:none;">
                    <div class="drm-progress-bar" id="drmProgressBar"></div>
                </div>

                <div class="drm-error" id="drmError" style="display:none;"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal" data-testid="document-replace-cancel-btn">Cancel</button>
                <button type="button" class="btn btn-sm" id="drmReplaceBtn" style="background:#3b32b3;color:#fff;" disabled data-testid="document-replace-submit-btn">
                    <i class="fa-solid fa-rotate"></i> Replace Document
                </button>
            </div>
        </div>
    </div>
</div>

<style>
    .drm-current { background: #f8fafc; border: 1px solid var(--border-color); border-radius: 12px; padding: 12px; margin-bottom: 16px; }
    .drm-current-row { display: flex; justify-content: space-between; gap: 10px; padding: 3px 0; font-size: 12.5px; }
    .drm-current-label { color: var(--text-muted); font-weight: 600; flex: 0 0 auto; }
    .drm-current-value { color: var(--text-dark); font-weight: 650; text-align: right; word-break: break-word; }
    .drm-select-label { font-size: 12.5px; font-weight: 700; color: var(--text-dark); margin-bottom: 8px; }
    .drm-dropzone {
        position: relative; border: 2px dashed var(--border-color); border-radius: 12px; padding: 24px 14px;
        text-align: center; background: #f8fafc; cursor: pointer; transition: border-color .15s, background .15s;
    }
    .drm-dropzone:hover, .drm-dropzone.drm-drag-over { border-color: #3b32b3; background: #eef2ff; }
    .drm-dropzone-icon { font-size: 26px; color: #3b32b3; margin-bottom: 8px; display: block; }
    .drm-dropzone-text { font-size: 12.5px; color: var(--text-dark); font-weight: 600; }
    .drm-browse-link { color: #3b32b3; text-decoration: underline; }
    .drm-dropzone-hint { font-size: 10.5px; color: var(--text-muted); margin-top: 4px; }
    .drm-file-input { position: absolute; inset: 0; opacity: 0; cursor: pointer; width: 100%; height: 100%; }
    .drm-selected-file {
        display: flex; align-items: center; gap: 10px; margin-top: 12px; background: #eef2ff;
        border: 1px solid #c7d2fe; border-radius: 10px; padding: 9px 11px;
    }
    .drm-selected-icon { color: #3b32b3; font-size: 16px; }
    .drm-selected-info { flex: 1; min-width: 0; }
    .drm-selected-name { font-size: 12.5px; font-weight: 650; color: var(--text-dark); word-break: break-word; }
    .drm-selected-meta { font-size: 10.5px; color: var(--text-muted); }
    .drm-selected-remove { border: none; background: transparent; color: var(--text-muted); font-size: 13px; }
    .drm-progress { margin-top: 12px; height: 7px; border-radius: 999px; background: #e2e8f0; overflow: hidden; }
    .drm-progress-bar { height: 100%; width: 0%; background: #3b32b3; transition: width .15s; }
    .drm-error { margin-top: 12px; background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; border-radius: 10px; padding: 9px 11px; font-size: 12px; }
</style>
<!-- 🌟 .doc-view-btn/.doc-replace-btn/.doc-actions-row are NOT styled here —
     View/Download render for every role that can see a document list
     (including Client/Super Admin on report_detail.php/completed_detail.php),
     not only Admin/Surveyor, but this whole partial is only ever included
     for Admin/Surveyor. Each including page defines those three rules
     itself, unconditionally, in its own <style> block instead. -->

