"""ADVS - export a TrOCR recognizer to ONNX, optionally INT8-quantized.

A BUILD step, not a runtime one: run it once per recognizer and point
TROCR_MODEL_PATH / TROCR_ACCURATE_MODEL_PATH at the output directory. The API
detects the layout itself (roi_field_ocr.is_onnx_export) and loads it through
onnxruntime instead of torch; nothing else in the pipeline changes.

Why bother: Stage 2's ROI+TrOCR pass is the pipeline's cost centre - a BIR page
runs 11 field crops through the recognizer, and on torch/CPU that is ~140s of
the ~160s page (see python/README.md). ONNX Runtime's CPU backend plus INT8
dynamic quantization is the standard lever for exactly this shape of workload
(encoder-decoder, batch 1, CPU-bound, sequential decode).

    # fp32 ONNX (no accuracy risk, modest speedup)
    env/Scripts/python.exe scripts/export_trocr_onnx.py \
        --model models/trocr-large-printed --output models/trocr-large-onnx

    # INT8 dynamic (bigger speedup, MUST be accuracy-checked - see below)
    env/Scripts/python.exe scripts/export_trocr_onnx.py \
        --model models/trocr-large-printed --output models/trocr-large-onnx-int8 --quantize

INT8 is dynamic (weights quantized, activations computed at runtime), so it
needs no calibration set. It is still a lossy transform: ALWAYS re-run the
field-fidelity check against a known-good reference before adopting an INT8
export, because Stage 2's whole job is reading values correctly. The failure
mode that matters here is a subtly wrong character in a date or TIN - a value
that still passes every regex, so nothing downstream flags it.

--------------------------------------------------------------------------
MEASURED 2026-07-25 - this script did NOT pay off on the 7.8GB dev box, and
it is kept as a build-machine tool rather than an adopted default. Read this
before spending a day re-deriving it.

Same 11 BIR field crops, same code path (roi_field_ocr.recognize_crop):

    torch trocr-base   4.1 s/crop   reference output
    ONNX fp32         16.8 s/crop   4x SLOWER, output faithful
    ONNX INT8          2.2 s/crop   1.9x faster, output CORRUPTED

INT8 changed 7 of the 11 crops and got worse at exactly the wrong things:
OCN `3U2907511725` -> `3029007511725`, `FEB 24 2025` -> `FE9 24 2025`,
trade name -> `EXPOSACTIONS SCDITIONS`. Unusable for document validation.

fp32 was slow because the export carries only ORT's generic passes, not the
transformer attention fusions (`--optimize O2`). The catch: on 7.8GB RAM,
BOTH `--optimize O2` and the default decoder-merge post-processing die in
`onnx.save` -> `proto.SerializeToString()` (MemoryError / EncodeError).
optimum's `fix_dynamic_axes` re-saves each graph WITHOUT external-data
streaming, so peak RAM is ~2x the graph, and TrOCR's graphs are 1.2GB each.
`--no-post-process` is what makes the export complete at all here - and it
is also what leaves it unoptimized.

trocr-large never exported at all on this box (MemoryError on the first
graph), which matters because large is the recognizer BIR actually uses.

To retry properly, use a machine with >=16GB RAM and drop `--no-post-process`:

    python scripts/export_trocr_onnx.py --model models/trocr-large-printed \\
        --output models/trocr-large-onnx --optimize O2

then re-run the field-fidelity comparison against a known-good page before
pointing TROCR_ACCURATE_MODEL_PATH at it. Do not adopt an INT8 export for BIR
without that check.
--------------------------------------------------------------------------
"""
from __future__ import annotations

import argparse
import shutil
import sys
from pathlib import Path

# The decoder carries the sequential cost (one pass per generated token, with
# cross-attention over the encoder's 577 patch embeddings) and is where INT8
# pays off most. The encoder runs once per crop. Quantizing both is the default
# because the measured win is what justifies this whole export; --skip-encoder
# is the fallback when an INT8 encoder costs recognition accuracy.
ENCODER_PREFIX = "encoder_model"


def parse_args(argv: list[str] | None = None) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--model", required=True,
                        help="local TrOCR snapshot dir (transformers save_pretrained layout)")
    parser.add_argument("--output", required=True, help="destination directory for the export")
    parser.add_argument("--quantize", action="store_true",
                        help="apply INT8 dynamic quantization after the fp32 export")
    parser.add_argument("--skip-encoder", action="store_true",
                        help="with --quantize: leave the vision encoder in fp32")
    parser.add_argument("--no-post-process", action="store_true",
                        help="skip optimum's decoder merge — lowers peak RAM during export")
    parser.add_argument("--optimize", choices=("O1", "O2", "O3", "O4"),
                        help="ONNX Runtime graph optimization level. Without it the export "
                             "gets only ORT's generic passes, not the transformer-specific "
                             "attention fusions — measured 4x SLOWER than torch at fp32.")
    return parser.parse_args(argv)


def graphs_to_quantize(output_dir: Path, skip_encoder: bool = False) -> list[Path]:
    """The .onnx graphs a quantization pass should cover, in a stable order.

    Excludes anything already quantized (re-running must be idempotent) and,
    with ``skip_encoder``, the vision encoder.
    """
    graphs = sorted(
        path for path in output_dir.glob("*.onnx")
        if "quantized" not in path.stem
        and not (skip_encoder and path.stem.startswith(ENCODER_PREFIX))
    )
    return graphs


def export(model_dir: Path, output_dir: Path, no_post_process: bool = False,
           optimize: str | None = None) -> None:
    from optimum.exporters.onnx import main_export

    main_export(
        model_name_or_path=str(model_dir),
        output=str(output_dir),
        task="image-to-text-with-past",   # keeps the KV-cache decoder branch
        opset=17,
        no_post_process=no_post_process,
        optimize=optimize,
    )


def quantize(output_dir: Path, skip_encoder: bool = False) -> list[Path]:
    from optimum.onnxruntime import AutoQuantizationConfig, ORTQuantizer

    config = AutoQuantizationConfig.avx512_vnni(is_static=False, per_channel=True)
    quantized: list[Path] = []
    for graph in graphs_to_quantize(output_dir, skip_encoder):
        quantizer = ORTQuantizer.from_pretrained(output_dir, file_name=graph.name)
        quantizer.quantize(save_dir=output_dir, quantization_config=config)
        quantized.append(graph)
    return quantized


def main(argv: list[str] | None = None) -> int:
    args = parse_args(argv)
    model_dir, output_dir = Path(args.model), Path(args.output)

    if not model_dir.is_dir():
        print(f"ERROR: no such model directory: {model_dir}", file=sys.stderr)
        return 1

    print(f"exporting {model_dir} -> {output_dir} (this takes a few minutes)")
    export(model_dir, output_dir, args.no_post_process, args.optimize)

    if args.quantize:
        done = quantize(output_dir, args.skip_encoder)
        print(f"quantized {len(done)} graph(s): {', '.join(p.name for p in done)}")
        # ORTModelForVision2Seq loads *_quantized.onnx only if the fp32 graphs
        # are gone; keeping both doubles the directory size and silently serves
        # the fp32 ones.
        for graph in done:
            graph.unlink()
        for graph in output_dir.glob("*_quantized.onnx"):
            graph.rename(graph.with_name(graph.name.replace("_quantized", "")))

    # The processor/tokenizer files live beside the graphs so the export dir is
    # a drop-in TROCR_MODEL_PATH.
    for name in ("preprocessor_config.json", "tokenizer.json", "tokenizer_config.json",
                 "special_tokens_map.json", "vocab.json", "merges.txt"):
        source = model_dir / name
        if source.is_file():
            shutil.copy2(source, output_dir / name)

    print(f"done: {output_dir}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
