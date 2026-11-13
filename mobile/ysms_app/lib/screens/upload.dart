// Upload (Pending Vessel → Pending Report, Pending Report → Completed): the same form and rules as the
// web app's upload screen, posted to ajax/upload_handler.php (its checks, PDF compression and emails).
import 'package:dio/dio.dart';
import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../api.dart';
import '../ui.dart';
import 'vessel.dart' show FileBadge;

const _maxTotal = 31 * 1024 * 1024, _maxZip = 30 * 1024 * 1024;

String mb(int b) => b < 1048576 ? '${(b / 1024).round().clamp(1, 1024)} KB' : '${(b / 1048576).toStringAsFixed(1)} MB';

class Picked {
  Picked(this.file, this.size);
  final PlatformFile file;
  final int size;
  String get name => file.name;
}

class UploadScreen extends StatefulWidget {
  const UploadScreen({super.key, required this.id, this.first});
  final int id;
  final Map<String, dynamic>? first;
  @override
  State<UploadScreen> createState() => _UploadScreenState();
}

class _UploadScreenState extends State<UploadScreen> {
  Map<String, dynamic>? _v;
  Object? _error;
  final _files = <String, List<Picked>>{'main': [], 'second': [], 'extra': []};
  late DateTime _date;
  final _parts = {'vlsfo': TextEditingController(), 'hsfo': TextEditingController(), 'lsmgo': TextEditingController()};
  final _total = TextEditingController();
  bool _totalTouched = false;
  String _err = '';
  double? _progress; // null = not sending
  String _status = '';

  @override
  void initState() {
    super.initState();
    _date = DateTime.now();
    for (final c in _parts.values) {
      c.addListener(_sum);
    }
    _load();
  }

  @override
  void dispose() {
    for (final c in [..._parts.values, _total]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _load() async {
    try {
      final v = await Api.i.get('survey', {'id': widget.id});
      if (!mounted) return;
      setState(() {
        _v = v;
        _error = null;
        final u = v['upload'] as Map?;
        final t = DateTime.tryParse(s(u?['today']));
        if (t != null) _date = t;
      });
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  void _sum() {
    if (_totalTouched) return;
    var any = false;
    var sum = 0.0;
    for (final c in _parts.values) {
      if (c.text.trim().isNotEmpty) {
        any = true;
        sum += double.tryParse(c.text.trim()) ?? 0;
      }
    }
    final t = any ? ((sum * 1000).round() / 1000).toString() : '';
    if (_total.text != t) _total.text = t;
  }

  Map get _u => (_v?['upload'] as Map?) ?? {};
  bool get _isVessel => _u['kind'] == 'vessel';
  bool get _excel => _u['has_excel'] == true || (_isVessel && _files['second']!.isNotEmpty);
  bool get _needRec => _isVessel && _u['recovery_na'] != true && !_excel;

  void _showErr(String m) {
    setState(() => _err = m);
    if (m.isNotEmpty) HapticFeedback.heavyImpact();
  }

  Future<void> _pick(String key) async {
    List<String>? ext;
    if (key == 'main') ext = ['pdf'];
    if (key == 'second') ext = _isVessel ? ['xlsx', 'xls', 'xlsm'] : ['zip'];
    const extraTypes = 'pdf doc docx xls xlsx xlsm csv txt ppt pptx zip rar 7z jpg jpeg png heic webp';
    if (key == 'extra') ext = extraTypes.split(' ');
    List<PlatformFile> picked;
    try {
      if (key == 'extra') {
        picked = await FilePicker.pickFiles(type: FileType.custom, allowedExtensions: ext);
      } else {
        final f = await FilePicker.pickFile(type: FileType.custom, allowedExtensions: ext);
        picked = f == null ? [] : [f];
      }
    } catch (_) {
      // some phones' pickers do not filter by type: pick anything, checked below
      try {
        picked = key == 'extra' ? await FilePicker.pickFiles() : [?await FilePicker.pickFile()];
      } catch (_) {
        return;
      }
    }
    if (picked.isEmpty) return;
    final out = <Picked>[];
    for (final f in picked) {
      out.add(Picked(f, await f.xFile.length()));
    }
    _showErr('');
    final first = out.first;
    final n = first.name.toLowerCase();
    if (key == 'main' && !n.endsWith('.pdf')) return _showErr('The report must be a PDF (.pdf) file.');
    if (key == 'second' && _isVessel && !RegExp(r'\.(xlsx|xls|xlsm)$').hasMatch(n)) {
      return _showErr('The Calculation Sheet must be an Excel file (.xlsx / .xls).');
    }
    if (key == 'second' && !_isVessel) {
      if (!n.endsWith('.zip')) return _showErr('Please choose a .zip file for the ZIP folder.');
      if (first.size > _maxZip) return _showErr('${first.name} is ${mb(first.size)}. The maximum is 30 MB.');
    }
    HapticFeedback.selectionClick();
    setState(() {
      _files[key] = key == 'extra' ? [..._files['extra']!, ...out] : [first];
    });
  }

  String _ymd(DateTime d) =>
      '${d.year.toString().padLeft(4, '0')}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';

  Future<void> _submit() async {
    _showErr('');
    final v = _v!;
    final today = DateTime.tryParse(s(_u['today'])) ?? DateTime.now();
    final minDate = DateTime.tryParse(s(_u['min_date']));
    final date = _ymd(_date);
    if (date.compareTo(_ymd(today)) > 0) return _showErr('The date cannot be in the future.');
    if (minDate != null && date.compareTo(_ymd(minDate)) < 0) {
      return _showErr('Report Completed date cannot be before the Survey Completed date.');
    }
    if (_isVessel && _u['has_pdf'] != true && _files['main']!.isEmpty) return _showErr('Please choose the PDF report.');
    final needRec = _needRec;
    if (needRec && _total.text.trim().isEmpty) return _showErr('Please enter the recovery (enter 0 if none).');
    final total = _files.values.expand((l) => l).fold<int>(0, (a, f) => a + f.size);
    if (total > _maxTotal) {
      return _showErr('The files add up to ${mb(total)} (limit 31 MB per upload). Please upload them in smaller batches.');
    }
    var noReport = '0';
    if (!_isVessel && _files['main']!.isEmpty) {
      // Same question report_detail.php asks when no Final Report is attached.
      final go = await showDialog<bool>(
        context: context,
        builder: (ctx) => AlertDialog(
          title: const Text('Complete without the final report?'),
          content: const Text('No Final Report (PDF) is attached. The vessel will move to Completed without it.'),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Go back')),
            FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Continue without report')),
          ],
        ),
      );
      if (go != true) return;
      noReport = '1';
    }
    if (!mounted) return;

    MultipartFile mf(Picked p) => MultipartFile.fromStream(() => p.file.xFile.openRead(), p.size, filename: p.name);
    FormData build(String csrf) {
      final fd = FormData();
      void f(String k, String val) => fd.fields.add(MapEntry(k, val));
      f('csrf_token', csrf);
      f('survey_id', '${v['id']}');
      f('current_status', s(v['status']));
      if (_isVessel) {
        if (_files['main']!.isNotEmpty) fd.files.add(MapEntry('pdf_report', mf(_files['main']!.first)));
        if (_files['second']!.isNotEmpty) fd.files.add(MapEntry('excel_report', mf(_files['second']!.first)));
        for (final x in _files['extra']!) {
          fd.files.add(MapEntry('additional_files[]', mf(x)));
        }
        f('survey_completed_date', date);
        if (needRec) {
          f('manual_recovery', '1');
          _parts.forEach((k, c) => f('manual_$k', c.text.trim()));
          f('manual_total', _total.text.trim());
        }
      } else {
        if (_files['main']!.isNotEmpty) fd.files.add(MapEntry('final_report_pdf', mf(_files['main']!.first)));
        if (_files['second']!.isNotEmpty) fd.files.add(MapEntry('zip_folder', mf(_files['second']!.first)));
        for (final x in _files['extra']!) {
          fd.files.add(MapEntry('extra_files[]', mf(x)));
        }
        f('report_completed_date', date);
        f('no_report_confirmed', noReport);
      }
      return fd;
    }

    setState(() {
      _progress = 0;
      _status = 'Uploading…';
    });
    FocusScope.of(context).unfocus();
    try {
      // A fresh session / form token first (stay signed in), so a long upload is not refused at the end.
      try {
        await Api.i.loadMe();
      } catch (_) {}
      final r = await Api.i.post(
        'ajax/upload_handler.php',
        build,
        headers: {'X-YSMS-Upload': '1'},
        onSendProgress: (a, b) {
          if (b <= 0 || !mounted) return;
          setState(() {
            _progress = a / b;
            if (a >= b) _status = 'Saving and sending the email…';
          });
        },
      );
      final code = r.statusCode ?? 500;
      if (code == 403) throw ApiError(code, 'Your session expired. Please sign in again.');
      if (code == 413) throw ApiError(code, 'The files are too large to upload together.');
      if (code >= 400) throw ApiError(code, 'The upload did not go through (error $code).');
      var ok = true, warn = false;
      var msg = 'Uploaded.';
      try {
        final fl = await Api.i.get('flash');
        if (fl['email_queued'] == true) _sendQueuedEmail();
        ok = fl['type'] != 'error';
        warn = fl['type'] == 'warning';
        if (s(fl['message']).isNotEmpty) msg = s(fl['message']);
      } catch (_) {}
      if (!ok) throw ApiError(400, msg);
      HapticFeedback.mediumImpact();
      if (!mounted) return;
      setState(() => _status = msg);
      await Future<void>.delayed(const Duration(milliseconds: 900));
      if (!mounted) return;
      toast(context, msg, ok: !warn);
      Navigator.of(context).pop(true);
    } on ApiError catch (e) {
      if (!mounted) return;
      setState(() => _progress = null);
      _showErr(e.offline ? 'No connection — the files were not uploaded. Try again.' : e.message);
    }
  }

  /// The admin email for this upload, sent in its own request (as the website's footer does).
  void _sendQueuedEmail() {
    final messenger = ScaffoldMessenger.maybeOf(context);
    Api.i
        .post('ajax/upload_email_send.php', (csrf) => <String, String>{}, csrfHeader: true)
        .then((r) {
          final d = Api.i.json(r);
          if (d == null || messenger == null) return;
          Future<void>.delayed(const Duration(seconds: 3), () {
            if (d['success'] != true) {
              messenger.showSnackBar(
                SnackBar(content: Text(s(d['message']).isEmpty ? 'Admin email will be retried automatically.' : s(d['message']))),
              );
              return;
            }
            for (final x in ((d['results'] as List?) ?? []).cast<Map>()) {
              if (x['status'] == 'sent') messenger.showSnackBar(SnackBar(content: Text(s(x['message']))));
              if (x['status'] == 'failed') {
                messenger.showSnackBar(SnackBar(content: Text('${s(x['message'])} — it will be retried automatically.')));
              }
            }
          });
        })
        .catchError((_) {});
  }

  @override
  Widget build(BuildContext context) {
    final v = _v;
    final title = v == null ? 'Upload' : (_isVessel ? 'Upload report' : 'Upload final report');
    return PopScope(
      canPop: _progress == null,
      child: Scaffold(
        appBar: AppBar(
          title: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(title),
              Text(
                s((v ?? widget.first)?['vessel']),
                style: TextStyle(fontSize: 12.5, fontWeight: FontWeight.w500, color: mutedText(context)),
              ),
            ],
          ),
        ),
        body: Stack(
          children: [
            if (_error != null && v == null)
              ErrorState(error: _error!, onRetry: _load)
            else if (v == null)
              const Padding(padding: EdgeInsets.all(16), child: CardSkeletons(count: 2))
            else if (v['upload'] == null)
              const EmptyState(icon: Icons.block_rounded, color: red, text: 'Uploading is not available for this vessel.')
            else
              _form(context, v),
            if (_progress != null) _overlay(context),
          ],
        ),
        bottomNavigationBar: v?['upload'] == null
            ? null
            : SafeArea(
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(16, 8, 16, 12),
                  child: FilledButton.icon(
                    style: FilledButton.styleFrom(
                      minimumSize: const Size.fromHeight(54),
                      backgroundColor: isDark(context) ? blue : navy,
                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
                    ),
                    onPressed: _progress != null ? null : _submit,
                    icon: Icon(_isVessel ? Icons.send_rounded : Icons.task_alt_rounded),
                    label: Text(
                      _isVessel ? 'Submit & Send' : 'Complete & Move',
                      style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w700),
                    ),
                  ),
                ),
              ),
      ),
    );
  }

  Widget _form(BuildContext context, Map<String, dynamic> v) {
    final u = _u;
    final minDate = DateTime.tryParse(s(u['min_date']));
    final today = DateTime.tryParse(s(u['today'])) ?? DateTime.now();
    return ListView(
      padding: const EdgeInsets.fromLTRB(16, 4, 16, 24),
      children: [
        Container(
          padding: const EdgeInsets.all(14),
          decoration: BoxDecoration(color: blue.withValues(alpha: .08), borderRadius: BorderRadius.circular(14)),
          child: Row(
            children: [
              Icon(_isVessel ? Icons.description_outlined : Icons.task_alt_rounded, color: blue),
              const SizedBox(width: 10),
              Expanded(
                child: Text.rich(
                  _isVessel
                      ? TextSpan(
                          children: [
                            const TextSpan(text: 'The PDF report moves this vessel to '),
                            const TextSpan(
                              text: 'Pending Reports',
                              style: TextStyle(fontWeight: FontWeight.w800),
                            ),
                            TextSpan(text: u['has_pdf'] == true ? ' (a PDF is already uploaded).' : '.'),
                          ],
                        )
                      : const TextSpan(
                          children: [
                            TextSpan(text: 'The final PDF moves this vessel to '),
                            TextSpan(
                              text: 'Completed',
                              style: TextStyle(fontWeight: FontWeight.w800),
                            ),
                            TextSpan(text: '.'),
                          ],
                        ),
                ),
              ),
            ],
          ),
        ),
        const SizedBox(height: 14),
        if (_isVessel) ...[
          _picker(
            'main',
            '1. Formal report (PDF)',
            u['has_pdf'] == true ? 'Already uploaded — add another only if needed' : 'Required · PDF',
            required: u['has_pdf'] != true,
          ),
          _picker(
            'second',
            '2. Calculation sheet (Excel)',
            u['has_excel'] == true ? 'Already uploaded · optional' : 'Optional · .xlsx / .xls',
          ),
        ] else ...[
          _picker('main', '1. Final report (PDF)', 'PDF'),
          _picker('second', '2. ZIP folder', 'Optional · photos / supporting documents, max 30 MB'),
        ],
        _picker('extra', 'Additional files', 'Optional · Excel, Word, PDF — you can pick several'),
        Box(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text.rich(
                TextSpan(
                  children: [
                    TextSpan(
                      text: _isVessel ? 'Survey completed date ' : 'Report completed date ',
                      style: const TextStyle(fontWeight: FontWeight.w700),
                    ),
                    const TextSpan(
                      text: '*',
                      style: TextStyle(color: red),
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 8),
              OutlinedButton.icon(
                style: OutlinedButton.styleFrom(minimumSize: const Size.fromHeight(50), alignment: Alignment.centerLeft),
                onPressed: () async {
                  final d = await showDatePicker(
                    context: context,
                    initialDate: _date,
                    firstDate: minDate ?? DateTime(2015),
                    lastDate: today,
                  );
                  if (d != null) setState(() => _date = d);
                },
                icon: const Icon(Icons.event_rounded),
                label: Text(_fmtDate(_date), style: const TextStyle(fontSize: 15.5, fontWeight: FontWeight.w600)),
              ),
              if (minDate != null)
                Padding(
                  padding: const EdgeInsets.only(top: 6),
                  child: Text('Survey completed on ${s(v['completed_on'])}.', style: TextStyle(fontSize: 12.5, color: mutedText(context))),
                ),
              if (_isVessel && u['recovery_na'] != true) ...[
                const SizedBox(height: 16),
                if (_excel)
                  const Row(
                    children: [
                      Icon(Icons.check_circle_rounded, color: green, size: 20),
                      SizedBox(width: 8),
                      Expanded(child: Text('Recovery will be read from the Calculation Sheet.')),
                    ],
                  )
                else ...[
                  const Text.rich(
                    TextSpan(
                      children: [
                        TextSpan(
                          text: 'Recovery (MT) ',
                          style: TextStyle(fontWeight: FontWeight.w700),
                        ),
                        TextSpan(
                          text: '*',
                          style: TextStyle(color: red),
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(height: 10),
                  Row(
                    children: [
                      for (final k in ['vlsfo', 'hsfo', 'lsmgo']) ...[
                        Expanded(child: _num(_parts[k]!, k.toUpperCase())),
                        const SizedBox(width: 8),
                      ],
                      Expanded(child: _num(_total, 'Total', onChanged: (t) => _totalTouched = t.isNotEmpty)),
                    ],
                  ),
                  const SizedBox(height: 6),
                  Text(
                    'Total fills in from VLSFO + HSFO + LSMGO; enter 0 if there was no recovery.',
                    style: TextStyle(fontSize: 12.5, color: mutedText(context)),
                  ),
                ],
              ],
            ],
          ),
        ),
        if (_err.isNotEmpty) ...[
          const SizedBox(height: 14),
          Container(
            padding: const EdgeInsets.all(14),
            decoration: BoxDecoration(
              color: const Color(0xFFFEF3F2),
              borderRadius: BorderRadius.circular(14),
              border: Border.all(color: const Color(0xFFFECDCA)),
            ),
            child: Row(
              children: [
                const Icon(Icons.error_outline_rounded, color: Color(0xFFD92D20)),
                const SizedBox(width: 10),
                Expanded(
                  child: Text(
                    _err,
                    style: const TextStyle(color: Color(0xFFB42318), fontWeight: FontWeight.w600),
                  ),
                ),
              ],
            ),
          ),
        ],
      ],
    );
  }

  String _fmtDate(DateTime d) {
    const m = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    return '${d.day.toString().padLeft(2, '0')} ${m[d.month - 1]} ${d.year}';
  }

  Widget _num(TextEditingController c, String label, {ValueChanged<String>? onChanged}) => TextField(
    controller: c,
    onChanged: onChanged,
    keyboardType: const TextInputType.numberWithOptions(decimal: true),
    inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'^\d*\.?\d{0,3}'))],
    decoration: InputDecoration(labelText: label, isDense: true, contentPadding: const EdgeInsets.symmetric(horizontal: 10, vertical: 14)),
  );

  Widget _picker(String key, String label, String hint, {bool required = false}) {
    final list = _files[key]!;
    return Box(
      margin: const EdgeInsets.only(bottom: 12),
      padding: EdgeInsets.zero,
      child: Column(
        children: [
          InkWell(
            borderRadius: BorderRadius.circular(18),
            onTap: _progress == null ? () => _pick(key) : null,
            child: Padding(
              padding: const EdgeInsets.all(14),
              child: Row(
                children: [
                  IconBadge(list.isEmpty ? Icons.upload_file_rounded : Icons.check_rounded, list.isEmpty ? blue : green),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text.rich(
                          TextSpan(
                            children: [
                              TextSpan(
                                text: label,
                                style: const TextStyle(fontWeight: FontWeight.w700),
                              ),
                              if (required)
                                const TextSpan(
                                  text: ' *',
                                  style: TextStyle(color: red),
                                ),
                            ],
                          ),
                        ),
                        const SizedBox(height: 2),
                        Text(hint, style: TextStyle(fontSize: 12.5, color: mutedText(context))),
                      ],
                    ),
                  ),
                  Icon(key == 'extra' || list.isEmpty ? Icons.add_rounded : Icons.swap_horiz_rounded, color: blue),
                ],
              ),
            ),
          ),
          for (var i = 0; i < list.length; i++)
            ListTile(
              dense: true,
              leading: FileBadge(name: list[i].name),
              title: Text(
                list[i].name,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: const TextStyle(fontWeight: FontWeight.w600),
              ),
              subtitle: Text(mb(list[i].size)),
              trailing: IconButton(
                tooltip: 'Remove ${list[i].name}',
                icon: const Icon(Icons.close_rounded),
                onPressed: _progress != null ? null : () => setState(() => list.removeAt(i)),
              ),
            ),
        ],
      ),
    );
  }

  Widget _overlay(BuildContext context) {
    final p = _progress ?? 0;
    return Positioned.fill(
      child: ColoredBox(
        color: Colors.black.withValues(alpha: .45),
        child: Center(
          child: Container(
            width: 250,
            padding: const EdgeInsets.all(24),
            decoration: BoxDecoration(color: cardBg(context), borderRadius: BorderRadius.circular(24)),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                SizedBox(
                  width: 110,
                  height: 110,
                  child: Stack(
                    fit: StackFit.expand,
                    children: [
                      CircularProgressIndicator(value: p >= 1 ? null : p, strokeWidth: 8, backgroundColor: lineColor(context)),
                      Center(
                        child: Text('${(p * 100).round()}%', style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w800)),
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 16),
                Text(
                  _status,
                  textAlign: TextAlign.center,
                  style: const TextStyle(fontWeight: FontWeight.w600),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
