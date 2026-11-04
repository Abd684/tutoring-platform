import 'dart:async';
import 'debounced_search.dart';

void check(bool condition, String message) {
  if (!condition) throw StateError(message);
}

Future<void> settle() => Future.delayed(const Duration(milliseconds: 70));

Future<void> main() async {
  var calls = 0;
  final states = <SearchState<String>>[];
  final controller = DebouncedSearch<String>(
    delay: const Duration(milliseconds: 15),
    fetch: (request) async {
      calls++;
      return SearchPage(
          ['هبة', 'سامر'].where((s) => s.contains(request.text)).toList(),
          1,
          request.text.isEmpty ? 2 : 1,
          1);
    },
    matches: (item, q) => item.contains(q),
    keyOf: (item) => item,
    onState: states.add,
  );
  controller.search('');
  await settle();
  controller.search('ه');
  check(states.last.preview && states.last.items.single == 'هبة',
      'Immediate local preview');
  controller.search('هب');
  controller.search('هبة');
  controller.search('هبة');
  await settle();
  check(calls == 2 && !states.last.preview,
      'Rapid typing and identical inputs must send one request');
  controller.search('');
  check(calls == 2 && states.last.fromCache, 'Repeated search uses cache');
  controller.search('هبة');
  check(calls == 2 && states.last.fromCache, 'Cached term reuses response');
  controller.search('هبة', filters: {'region_id': 1});
  check(states.last.items.isEmpty,
      'Changed filters must not preview old-filter data');
  await settle();
  check(calls == 3, 'Filter is part of cache key');
  controller.refresh();
  await settle();
  check(calls == 4, 'Refresh invalidates cache');
  controller.dispose();
  print(
      'PASS: debounce, local preview, duplicate suppression, cache, filters, refresh');

  final pending = <String, Completer<SearchPage<String>>>{};
  final staleStates = <SearchState<String>>[];
  final stale = DebouncedSearch<String>(
    delay: const Duration(milliseconds: 15),
    fetch: (request) {
      final c = Completer<SearchPage<String>>();
      pending[request.text] = c;
      return c.future;
    },
    matches: (s, q) => s.contains(q),
    keyOf: (s) => s,
    onState: staleStates.add,
  );
  stale.search('old');
  await settle();
  stale.search('new');
  pending['old']!.complete(SearchPage(['old'], 1, 1, 1));
  await settle();
  check(staleStates.last.preview && !staleStates.last.items.contains('old'),
      'Ignore old response even during debounce');
  pending['new']!.complete(SearchPage(['new'], 1, 1, 1));
  await settle();
  check(staleStates.last.items.single == 'new', 'Latest response wins');
  stale.search('disposed');
  stale.dispose();
  await settle();
  check(!pending.containsKey('disposed'), 'Dispose cancels scheduled requests');
  print('PASS: stale response suppression and dispose');

  var pageCalls = 0;
  SearchState<String>? pagedState;
  final pages = DebouncedSearch<String>(
    delay: const Duration(milliseconds: 15),
    fetch: (request) async {
      pageCalls++;
      return request.page == 1
          ? SearchPage(['a'], 1, 2, 2)
          : SearchPage(['a', 'b'], 2, 2, 2);
    },
    matches: (s, q) => true,
    keyOf: (s) => s,
    onState: (s) => pagedState = s,
  );
  pages.search('');
  await settle();
  await Future.wait([pages.loadNextPage(), pages.loadNextPage()]);
  await pages.loadNextPage();
  check(pageCalls == 2 && pagedState!.items.join() == 'ab',
      'Pagination prevents duplicate requests and items');
  pages.dispose();
  print('PASS: pagination and deduplication');

  var attempts = 0;
  SearchState<String>? errorState;
  final errors = DebouncedSearch<String>(
    delay: const Duration(milliseconds: 15),
    fetch: (request) async {
      if (++attempts == 1) throw StateError('offline');
      return SearchPage(['ok'], 1, 1, 1);
    },
    matches: (s, q) => true,
    keyOf: (s) => s,
    onState: (s) => errorState = s,
  );
  errors.search('retry');
  await settle();
  check(errorState!.error != null && errorState!.preview,
      'Network error must not appear as confirmed empty result');
  errors.search('retry');
  await settle();
  check(attempts == 2 && errorState!.items.single == 'ok',
      'Error must not be cached');
  errors.dispose();
  print('PASS: errors and retry');

  var ttlCalls = 0;
  final ttl = DebouncedSearch<String>(
    delay: const Duration(milliseconds: 5),
    cacheTtl: const Duration(milliseconds: 20),
    fetch: (request) async {
      ttlCalls++;
      return SearchPage(['ok'], 1, 1, 1);
    },
    matches: (s, q) => true,
    keyOf: (s) => s,
    onState: (s) {},
  );
  ttl.search('x');
  await settle();
  ttl.search('x');
  await settle();
  check(ttlCalls == 2, 'Expired cache must refetch');
  ttl.dispose();
  print('PASS: cache expiry');
}
