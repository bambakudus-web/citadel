// ── CITADEL FACE VERIFICATION MODULE ──
// Uses face-api.js for face detection, recognition and liveness

const FaceVerify = (() => {
  const MODEL_URL = '/assets/models';
  let modelsLoaded = false;
  let videoStream  = null;
  let blinkCount   = 0;
  let lastEAR      = 1.0;
  let livenessPass = false;

  // ── Load models ──
  async function loadModels() {
    if (modelsLoaded) return true;
    try {
      await Promise.all([
        faceapi.nets.tinyFaceDetector.loadFromUri(MODEL_URL),
        faceapi.nets.faceLandmark68Net.loadFromUri(MODEL_URL),
        faceapi.nets.faceRecognitionNet.loadFromUri(MODEL_URL),
      ]);
      modelsLoaded = true;
      return true;
    } catch (e) {
      console.error('Face models failed to load:', e);
      return false;
    }
  }

  // ── Eye aspect ratio for blink detection ──
  function eyeAspectRatio(landmarks, eyePoints) {
    const pts = eyePoints.map(i => landmarks.positions[i]);
    const A = Math.hypot(pts[1].x - pts[5].x, pts[1].y - pts[5].y);
    const B = Math.hypot(pts[2].x - pts[4].x, pts[2].y - pts[4].y);
    const C = Math.hypot(pts[0].x - pts[3].x, pts[0].y - pts[3].y);
    return (A + B) / (2.0 * C);
  }

  // ── Detect face in video frame ──
  async function detectFace(video) {
    const det = await faceapi
      .detectSingleFace(video, new faceapi.TinyFaceDetectorOptions({ inputSize: 224, scoreThreshold: 0.5 }))
      .withFaceLandmarks()
      .withFaceDescriptor();
    return det || null;
  }

  // ── Get descriptor from image element or canvas ──
  async function getDescriptor(imgEl) {
    const det = await faceapi
      .detectSingleFace(imgEl, new faceapi.TinyFaceDetectorOptions({ inputSize: 224 }))
      .withFaceLandmarks()
      .withFaceDescriptor();
    return det ? Array.from(det.descriptor) : null;
  }

  // ── Compare two descriptors (returns % match) ──
  function compareDescriptors(d1, d2) {
    if (!d1 || !d2 || d1.length !== 128 || d2.length !== 128) return 0;
    const dist = faceapi.euclideanDistance(
      new Float32Array(d1),
      new Float32Array(d2)
    );
    // Convert distance to percentage (0.6 = 0% match, 0 = 100% match)
    const score = Math.max(0, Math.min(100, Math.round((1 - dist / 0.6) * 100)));
    return score;
  }

  // ── Check blink for liveness ──
  function checkBlink(landmarks) {
    const leftEye  = [36,37,38,39,40,41];
    const rightEye = [42,43,44,45,46,47];
    const ear = (eyeAspectRatio(landmarks, leftEye) + eyeAspectRatio(landmarks, rightEye)) / 2;

    if (lastEAR > 0.25 && ear < 0.2) {
      // Eye just closed
    } else if (lastEAR < 0.2 && ear > 0.25) {
      // Eye just opened — blink complete
      blinkCount++;
    }
    lastEAR = ear;
    return blinkCount;
  }

  // ── Start camera ──
  async function startCamera(videoEl, onFaceDetected, onBlink) {
    try {
      videoStream = await navigator.mediaDevices.getUserMedia({
        video: { facingMode: 'user', width: 320, height: 240 }
      });
      videoEl.srcObject = videoStream;
      await new Promise(r => videoEl.onloadedmetadata = r);
      videoEl.play();

      // Start detection loop
      blinkCount   = 0;
      livenessPass = false;
      const loop   = setInterval(async () => {
        if (!videoEl.paused && videoEl.readyState === 4) {
          const det = await detectFace(videoEl);
          if (det) {
            const blinks = checkBlink(det.landmarks);
            if (onBlink) onBlink(blinks);
            if (blinks >= 1) livenessPass = true; // 1 blink = liveness confirmed
            if (onFaceDetected) onFaceDetected(det, livenessPass);
          }
        }
      }, 400);

      return loop;
    } catch (e) {
      console.error('Camera error:', e);
      throw e;
    }
  }

  // ── Stop camera ──
  function stopCamera(loopId) {
    if (loopId) clearInterval(loopId);
    if (videoStream) {
      videoStream.getTracks().forEach(t => t.stop());
      videoStream = null;
    }
  }

  // ── Capture frame as base64 ──
  function captureFrame(videoEl) {
    const canvas = document.createElement('canvas');
    canvas.width  = videoEl.videoWidth  || 320;
    canvas.height = videoEl.videoHeight || 240;
    canvas.getContext('2d').drawImage(videoEl, 0, 0);
    return canvas.toDataURL('image/jpeg', 0.85);
  }

  // ── Full enrollment flow ──
  // Takes multiple samples, averages descriptors
  async function enrollFace(videoEl, statusCb) {
    statusCb('Looking for your face...');
    const samples = [];
    for (let i = 0; i < 5; i++) {
      await new Promise(r => setTimeout(r, 600));
      const det = await detectFace(videoEl);
      if (det) {
        samples.push(Array.from(det.descriptor));
        statusCb(`Capturing sample ${samples.length}/5...`);
      }
    }
    if (samples.length < 3) {
      return { success: false, error: 'Could not capture enough face samples. Ensure good lighting and face is visible.' };
    }
    // Average the descriptors
    const avg = new Array(128).fill(0);
    samples.forEach(d => d.forEach((v, i) => avg[i] += v / samples.length));
    statusCb('Face enrolled!');
    return { success: true, descriptor: avg, samples: samples.length };
  }

  // ── Full verification flow ──
  async function verifyFace(videoEl, enrolledDescriptor, statusCb) {
    statusCb('Detecting face...');
    let attempts = 0;
    while (attempts < 10) {
      await new Promise(r => setTimeout(r, 300));
      const det = await detectFace(videoEl);
      if (det) {
        const currentDesc = Array.from(det.descriptor);
        const score = compareDescriptors(enrolledDescriptor, currentDesc);
        statusCb(`Face match: ${score}%`);
        return {
          success:          score >= 60,
          score:            score,
          liveness:         livenessPass,
          descriptor:       currentDesc,
          auto_approvable:  score >= 85 && livenessPass,
        };
      }
      attempts++;
    }
    return { success: false, score: 0, liveness: false, error: 'No face detected' };
  }

  return { loadModels, startCamera, stopCamera, captureFrame, enrollFace, verifyFace, compareDescriptors, getDescriptor };
})();
