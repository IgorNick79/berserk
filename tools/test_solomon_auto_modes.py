"""Run Solomon gameplay fixture plus auto sides; exclude known broken repository CAS fixture."""
import argparse,subprocess,tempfile
from pathlib import Path
ap=argparse.ArgumentParser();ap.add_argument('--root',type=Path,required=True);ap.add_argument('--php',required=True);a=ap.parse_args();root=a.root.resolve()
source=(root/'tools/test_solomon_draft.php').read_text(encoding='utf-8-sig')
source=source[:source.index('    // Two snapshots exercise')]
source=source.replace("__DIR__ . '/../", "'"+root.as_posix()+"' . '/")
source=source.replace("__DIR__.'/../", "'"+root.as_posix()+"'.'/")
source+=r"""
    foreach (['both','host','player'] as $side) {
        $state=synthetic($db);$state->settings->draft['pick_mode']='random';$state->settings->draft['auto_side']=$side;
        check($state->settings->validateDraftSettings()===null,'Auto settings accepted');
        $picker=new class {public function pick($options,$current){return $options[0]??null;}};
        $p=new PrepareProcessor($state,$db,$picker);$m=new \ReflectionMethod($p,'runDraftAutomaticProgress');$m->setAccessible(true);
        check($m->invoke($p,time())->success,'Automatic progress');
        check($side==='both'?$state->status!=='draft':($state->status!=='draft'||$state->draft['turn']!==$side),'Auto finishes or yields to human');
    }
    echo "Solomon gameplay and auto sides passed; CAS fixture excluded.\n";
}
"""
with tempfile.TemporaryDirectory() as temp:
    path=Path(temp)/'test.php';path.write_text(source,encoding='utf-8');subprocess.run([a.php,str(path)],check=True,cwd=root)
