"""Rerun Stage 5B HTTP races/rollback on current code; obsolete placeholder UI excluded.
Current gameplay browser coverage is tools/test_integration_browser.cjs instead.
"""
import pathlib,sys
sys.dont_write_bytecode=True
path=pathlib.Path(__file__).with_name('test_lobby_http.py')
source=path.read_text(encoding='utf-8')
start=source.index('        # Real browser tests with separate storage contexts')
end=source.index("        (LAB/'postchecks.txt')",start)
source=source[:start]+"        # Stage 5B placeholder UI intentionally excluded; see Stage 5C browser runner.\n"+source[end:]
exec(compile(source,str(path),'exec'),{'__name__':'__main__','__file__':str(path)})
